<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Services\CourseAccessService;
use App\Services\CourseFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LessonController extends Controller
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly CourseFormatter $formatter
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    */

    public function adminIndex($courseId)
    {
        $course = Course::find($courseId);

        if (! $course) {
            return $this->error('Course not found', 404);
        }

        $lessons = $course->lessons()->with('quiz')->get();

        return $this->success(
            'Lessons retrieved successfully',
            $lessons->map(fn (Lesson $lesson) => $this->formatter->formatAdminLesson($lesson))->values()->all()
        );
    }

    public function adminShow($id)
    {
        $lesson = Lesson::with('quiz')->find($id);

        if (! $lesson) {
            return $this->error('Lesson not found', 404);
        }

        return $this->success('Lesson retrieved successfully', $this->formatter->formatAdminLesson($lesson));
    }

    public function store(Request $request)
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id',
            'course_section_id' => 'nullable|exists:course_sections,id',
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|in:video,text,quiz',
            'video_source' => 'nullable|in:external,upload',
            'video_url' => 'nullable|url',
            'video' => $this->videoRules(),
            'duration_seconds' => 'nullable|integer|min:0',
            'content' => 'nullable|string',
            'is_preview' => 'nullable|boolean',
            'is_published' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $course = Course::findOrFail($request->course_id);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'course_section_id' => $request->course_section_id,
            'title' => $request->title,
            'slug' => $request->filled('slug') ? Str::slug($request->slug) : Str::slug($request->title),
            'description' => $request->description,
            'type' => $request->input('type', 'video'),
            'video_source' => $request->input('type', 'video') === 'video' ? ($request->video_source ?: 'external') : null,
            'video_url' => $request->video_url,
            'duration_seconds' => (int) $request->input('duration_seconds', 0),
            'content' => $request->content,
            'is_preview' => $request->boolean('is_preview'),
            'is_published' => $request->has('is_published') ? $request->boolean('is_published') : true,
            'sort_order' => (int) $request->input('sort_order', 0),
        ]);

        $this->attachVideo($request, $lesson, $course);

        $course->refreshLessonStats();

        return $this->success(
            'Lesson created successfully',
            $this->formatter->formatAdminLesson($lesson->fresh('quiz'))
        );
    }

    public function update(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:lessons,id',
            'course_section_id' => 'nullable|exists:course_sections,id',
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|in:video,text,quiz',
            'video_source' => 'nullable|in:external,upload',
            'video_url' => 'nullable|url',
            'video' => $this->videoRules(),
            'duration_seconds' => 'nullable|integer|min:0',
            'content' => 'nullable|string',
            'is_preview' => 'nullable|boolean',
            'is_published' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'remove_video' => 'nullable|boolean',
        ]);

        $lesson = Lesson::findOrFail($request->id);
        $type = $request->input('type', $lesson->type);

        $payload = [
            'course_section_id' => $request->has('course_section_id') ? $request->course_section_id : $lesson->course_section_id,
            'title' => $request->title,
            'slug' => $request->filled('slug') ? Str::slug($request->slug) : $lesson->slug,
            'description' => $request->description,
            'type' => $type,
            'duration_seconds' => $request->has('duration_seconds') ? (int) $request->duration_seconds : $lesson->duration_seconds,
            'content' => $request->content,
            'is_preview' => $request->has('is_preview') ? $request->boolean('is_preview') : $lesson->is_preview,
            'is_published' => $request->has('is_published') ? $request->boolean('is_published') : $lesson->is_published,
            'sort_order' => $request->has('sort_order') ? (int) $request->sort_order : $lesson->sort_order,
        ];

        if ($type !== 'video') {
            $payload['video_source'] = null;
            $payload['video_url'] = null;
            $this->deleteStoredVideo($lesson->video_path);
            $payload['video_path'] = null;
        } elseif ($request->has('video_source')) {
            $payload['video_source'] = $request->video_source;
        } elseif ($lesson->video_source === null) {
            $payload['video_source'] = 'external';
        }

        if ($request->has('video_url')) {
            $payload['video_url'] = $request->video_url;
        }

        if ($request->boolean('remove_video')) {
            $this->deleteStoredVideo($lesson->video_path);
            $payload['video_path'] = null;
            $payload['video_source'] = $type === 'video' ? ($payload['video_source'] ?? 'external') : null;
        }

        $lesson->update($payload);

        if ($type === 'video') {
            $this->attachVideo($request, $lesson, $lesson->course);
        }

        $lesson->course->refreshLessonStats();

        return $this->success(
            'Lesson updated successfully',
            $this->formatter->formatAdminLesson($lesson->fresh(['quiz', 'course']))
        );
    }

    public function destroy($id)
    {
        $lesson = Lesson::find($id);

        if (! $lesson) {
            return $this->error('Lesson not found', 404);
        }

        $course = $lesson->course;
        $this->deleteStoredVideo($lesson->video_path);
        $lesson->delete();

        $course?->refreshLessonStats();

        return $this->success('Lesson deleted successfully', null);
    }

    public function reorder(Request $request)
    {
        $request->validate([
            'lessons' => 'required|array',
            'lessons.*.id' => 'required|exists:lessons,id',
            'lessons.*.sort_order' => 'required|integer',
            'lessons.*.course_section_id' => 'nullable|exists:course_sections,id',
        ]);

        foreach ($request->lessons as $item) {
            $payload = ['sort_order' => (int) $item['sort_order']];

            if (array_key_exists('course_section_id', $item)) {
                $payload['course_section_id'] = $item['course_section_id'];
            }

            Lesson::where('id', $item['id'])->update($payload);
        }

        return $this->success('Lessons reordered successfully', null);
    }

    /*
    |--------------------------------------------------------------------------
    | Student
    |--------------------------------------------------------------------------
    */

    /**
     * Full learning view of a course for the signed-in student.
     */
    public function learning(Request $request, $slug)
    {
        $course = Course::query()
            ->with([
                'category',
                'sections.lessons.quiz',
                'lessons.quiz',
            ])
            ->where('slug', $slug)
            ->first();

        if (! $course) {
            return $this->error('Course not found', 404);
        }

        $user = $request->user();
        $hasAccess = $user
            ? $this->access->hasActiveAccess($user, $course)
            : false;

        $progress = $user
            ? $this->access->progress($user, $course)
            : [
                'total_lessons' => $course->publishedLessons()->count(),
                'completed_lessons' => 0,
                'percentage' => 0,
                'is_complete' => false,
                'completed_lesson_ids' => [],
                'enrollment' => null,
            ];

        $progressByLesson = [];
        if ($user) {
            $progressByLesson = LessonProgress::query()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->get()
                ->keyBy('lesson_id');
        }

        $data = $this->formatter->formatCourse($course, true);
        $data['access'] = [
            'has_access' => $hasAccess,
            'enrollment' => $progress['enrollment'],
        ];
        $data['progress'] = $progress;

        $data['sections'] = $course->sections->map(function ($section) use ($user, $hasAccess, $progressByLesson) {
            return [
                'id' => $section->id,
                'title' => $section->title,
                'description' => $section->description,
                'sort_order' => $section->sort_order,
                'lessons' => $section->lessons
                    ->where('is_published', true)
                    ->values()
                    ->map(fn (Lesson $lesson) => $this->formatter->formatLesson(
                        $lesson,
                        $user,
                        $progressByLesson[$lesson->id] ?? null,
                        $hasAccess
                    ))
                    ->all(),
            ];
        })->values()->all();

        $data['quizzes'] = $course->quizzes()
            ->where('is_published', true)
            ->withCount('questions')
            ->get()
            ->map(fn ($quiz) => [
                'id' => $quiz->id,
                'course_id' => $quiz->course_id,
                'lesson_id' => $quiz->lesson_id,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'pass_percentage' => $quiz->pass_percentage,
                'time_limit_minutes' => $quiz->time_limit_minutes,
                'max_attempts' => $quiz->max_attempts,
                'questions_count' => $quiz->questions_count,
            ])
            ->values()
            ->all();

        return $this->success('Course learning data retrieved successfully', $data);
    }

    public function show(Request $request, $id)
    {
        $lesson = Lesson::with(['course', 'quiz'])->find($id);

        if (! $lesson || ! $lesson->is_published) {
            return $this->error('Lesson not found', 404);
        }

        $user = $request->user();
        $hasAccess = $user ? $this->access->canAccessLesson($user, $lesson) : $lesson->is_preview;

        if (! $hasAccess) {
            return $this->error('You do not have access to this lesson', null);
        }

        $progress = $user
            ? LessonProgress::query()
                ->where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->first()
            : null;

        return $this->success(
            'Lesson retrieved successfully',
            $this->formatter->formatLesson($lesson, $user, $progress, true)
        );
    }

    /**
     * Stream a privately stored lesson video. Protected by a temporary signed URL.
     */
    public function stream(Request $request, Lesson $lesson)
    {
        if ($lesson->video_source !== 'upload' || ! $lesson->video_path) {
            return $this->error('This lesson has no streamable video', 404);
        }

        $user = $request->user('sanctum');

        if (! $user && $request->filled('user')) {
            $user = \App\Models\User::find($request->query('user'));
        }

        $allowed = $request->hasValidSignature()
            && ($lesson->is_preview || ($user && $this->access->hasActiveAccess($user, $lesson->course)));

        if (! $allowed) {
            return $this->error('This video URL is no longer valid', null);
        }

        $disk = Storage::disk(config('courses.video_disk', 'local'));

        if (! $disk->exists($lesson->video_path)) {
            return $this->error('Video file not found', 404);
        }

        return response()->file($disk->path($lesson->video_path), [
            'Content-Type' => 'video/mp4',
            'Cache-Control' => 'private, max-age=0, no-transform',
        ]);
    }

    public function complete(Request $request, $id)
    {
        $lesson = Lesson::find($id);

        if (! $lesson) {
            return $this->error('Lesson not found', 404);
        }

        if (! $this->access->canAccessLesson($request->user(), $lesson)) {
            return $this->error('You do not have access to this lesson');
        }

        $this->access->markLessonComplete($request->user(), $lesson);

        return $this->success(
            'Lesson marked as completed',
            $this->access->progress($request->user(), $lesson->course)
        );
    }

    public function incomplete(Request $request, $id)
    {
        $lesson = Lesson::find($id);

        if (! $lesson) {
            return $this->error('Lesson not found', 404);
        }

        if (! $this->access->canAccessLesson($request->user(), $lesson)) {
            return $this->error('You do not have access to this lesson');
        }

        $this->access->markLessonIncomplete($request->user(), $lesson);

        return $this->success(
            'Lesson marked as incomplete',
            $this->access->progress($request->user(), $lesson->course)
        );
    }

    public function trackProgress(Request $request, $id)
    {
        $request->validate([
            'position_seconds' => 'required|integer|min:0',
        ]);

        $lesson = Lesson::find($id);

        if (! $lesson) {
            return $this->error('Lesson not found', 404);
        }

        if (! $this->access->canAccessLesson($request->user(), $lesson)) {
            return $this->error('You do not have access to this lesson');
        }

        $progress = $this->access->updateWatchPosition(
            $request->user(),
            $lesson,
            (int) $request->position_seconds
        );

        return $this->success('Progress saved', [
            'lesson_id' => $lesson->id,
            'last_position_seconds' => $progress->last_position_seconds,
            'is_completed' => $progress->completed_at !== null,
            'progress' => $this->access->progress($request->user(), $lesson->course),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function videoRules(): string
    {
        $maxKb = (int) config('courses.video_max_size_kb', 524288);

        return 'nullable|file|max:'.$maxKb.'|mimetypes:video/mp4,video/webm,video/quicktime,video/x-matroska';
    }

    private function attachVideo(Request $request, Lesson $lesson, ?Course $course): void
    {
        if (! $request->hasFile('video') || ! $course) {
            return;
        }

        $diskName = config('courses.video_disk', 'local');
        $disk = Storage::disk($diskName);
        $file = $request->file('video');

        $existing = $lesson->video_path;
        $extension = $file->getClientOriginalExtension() ?: 'mp4';
        $name = Str::uuid().'.'.$extension;
        $path = 'courses/'.$course->id.'/lessons/'.$lesson->id.'/'.$name;

        $disk->putFileAs(dirname($path), $file, basename($path));

        if ($existing && $existing !== $path) {
            $this->deleteStoredVideo($existing);
        }

        $lesson->forceFill([
            'video_source' => 'upload',
            'video_path' => $path,
        ])->save();
    }

    private function deleteStoredVideo(?string $path): void
    {
        if (! $path) {
            return;
        }

        $disk = Storage::disk(config('courses.video_disk', 'local'));

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
