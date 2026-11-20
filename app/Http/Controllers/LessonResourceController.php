<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonResource;
use App\Services\CourseAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Downloadable files / links attached to a course (lesson_id null) or to a lesson.
 * Files live on a private disk and are only streamed to students with active access.
 */
class LessonResourceController extends Controller
{
    public function __construct(private readonly CourseAccessService $access)
    {
    }

    private function disk(?string $name = null)
    {
        return Storage::disk($name ?: config('courses.resource_disk', 'local'));
    }

    /*
    |--------------------------------------------------------------------------
    | Admin / instructor
    |--------------------------------------------------------------------------
    */

    public function index($lessonId)
    {
        if ($d = $this->denyLesson($lessonId)) {
            return $d;
        }

        $lesson = Lesson::with('resources')->find($lessonId);

        if (! $lesson) {
            return $this->error('Lesson not found', 404);
        }

        return $this->success('Resources retrieved successfully', $lesson->resources);
    }

    /** Every attachment of a course (course-level and lesson-level). */
    public function courseIndex($courseId)
    {
        if ($d = $this->denyCourse($courseId)) {
            return $d;
        }

        $items = LessonResource::query()
            ->with('lesson:id,title')
            ->where('course_id', $courseId)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return $this->success('Attachments retrieved successfully', $items);
    }

    public function store(Request $request)
    {
        $request->validate([
            'course_id' => 'required_without:lesson_id|nullable|exists:courses,id',
            'lesson_id' => 'required_without:course_id|nullable|exists:lessons,id',
            'title' => 'required|string|max:255',
            'file' => 'nullable|file|max:51200|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,zip,txt,csv,png,jpg,jpeg,webp',
            'external_url' => ['nullable', 'url:https', 'max:2048'],
            'is_downloadable' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $lesson = $request->filled('lesson_id') ? Lesson::find($request->lesson_id) : null;
        $course = $lesson ? $lesson->course : Course::find($request->course_id);

        if (! $course) {
            return $this->error('Course not found', 404);
        }

        if (! $this->canManageCourse($course)) {
            return $this->forbidden();
        }

        if ($lesson && $request->filled('course_id') && (int) $request->course_id !== (int) $lesson->course_id) {
            return $this->error('The lesson does not belong to this course');
        }

        if (! $request->hasFile('file') && ! $request->filled('external_url')) {
            return $this->error('Provide a file or an external_url');
        }

        $data = [
            'course_id' => $course->id,
            'lesson_id' => $lesson?->id,
            'title' => $request->title,
            'is_downloadable' => $request->has('is_downloadable') ? $request->boolean('is_downloadable') : true,
            'sort_order' => (int) $request->input('sort_order', 0),
            'external_url' => $request->external_url,
        ];

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $diskName = config('courses.resource_disk', 'local');
            $path = 'resources/'.$course->id.'/'.($lesson?->id ?? 'course').'/'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
            $this->disk($diskName)->putFileAs(dirname($path), $file, basename($path));

            $data += [
                'disk' => $diskName,
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ];
        }

        return $this->success('Attachment created successfully', LessonResource::create($data)->fresh());
    }

    public function destroy($id)
    {
        $resource = LessonResource::find($id);

        if (! $resource) {
            return $this->error('Resource not found', 404);
        }

        if ($d = $this->denyCourse($resource->course_id)) {
            return $d;
        }

        if ($resource->path) {
            $this->disk($resource->disk)->delete($resource->path);
        }

        $resource->delete();

        return $this->success('Resource deleted successfully', null);
    }

    /*
    |--------------------------------------------------------------------------
    | Students
    |--------------------------------------------------------------------------
    */

    /**
     * Course attachments. Everyone sees what exists (locked); enrolled students get download URLs.
     */
    public function forCourse(Request $request, $slug)
    {
        $course = Course::query()->published()->where('slug', $slug)->first();

        if (! $course) {
            return $this->error('Course not found', 404);
        }

        $user = $request->user('sanctum');
        $hasAccess = $user && ($this->access->hasActiveAccess($user, $course) || $this->canManageCourse($course));

        $items = LessonResource::query()
            ->with('lesson:id,title,is_published')
            ->where('course_id', $course->id)
            ->where('is_downloadable', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->filter(fn (LessonResource $r) => ! $r->lesson || $r->lesson->is_published)
            ->map(fn (LessonResource $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'kind' => $r->kind,
                'mime_type' => $r->mime_type,
                'size' => $r->size,
                'lesson' => $r->lesson ? ['id' => $r->lesson->id, 'title' => $r->lesson->title] : null,
                'locked' => ! $hasAccess,
                'external_url' => $hasAccess ? $r->external_url : null,
                'download_url' => $hasAccess && $r->path ? url('api/lesson-resources/'.$r->id.'/download') : null,
            ])->values();

        return $this->success('Attachments retrieved successfully', $items);
    }

    /** Download (or inline preview with ?inline=1 for PDFs/images). Requires active access. */
    public function download(Request $request, $id)
    {
        $resource = LessonResource::with('lesson')->find($id);

        if (! $resource || ! $resource->is_downloadable || ! $resource->path) {
            return $this->error('Resource not found', 404);
        }

        $course = Course::find($resource->course_id);
        $user = $request->user();

        $allowed = $course && (
            $this->access->hasActiveAccess($user, $course)
            || $this->canManageCourse($course)
            || ($resource->lesson && $this->access->canAccessLesson($user, $resource->lesson))
        );

        if (! $allowed) {
            return $this->error('You do not have access to this resource');
        }

        $disk = $this->disk($resource->disk);

        if (! $disk->exists($resource->path)) {
            return $this->error('File not found', 404);
        }

        $name = Str::slug($resource->title).'.'.pathinfo($resource->path, PATHINFO_EXTENSION);

        if ($request->boolean('inline') && in_array($resource->kind, ['pdf', 'image'], true)) {
            return $disk->response($resource->path, $name, ['Content-Type' => $resource->mime_type]);
        }

        return $disk->download($resource->path, $name);
    }
}
