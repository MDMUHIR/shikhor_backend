<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;
use App\Services\CourseFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CourseController extends Controller
{
    public function __construct(private readonly CourseFormatter $formatter)
    {
    }

    public function index(Request $request)
    {
        $query = Course::query()->published()->with('category');

        $this->applyFilters($query, $request);

        $perPage = min(max((int) $request->query('per_page', 12), 1), 100);
        $courses = $query->paginate($perPage)->withQueryString();
        $courses->setCollection(
            $courses->getCollection()->map(fn (Course $course) => $this->formatter->formatCourse($course))
        );

        return $this->success('Courses retrieved successfully', $courses);
    }

    public function show(Request $request, $slug)
    {
        $course = Course::query()
            ->published()
            ->with([
                'category',
                'sections.lessons' => fn ($query) => $query->where('is_published', true),
            ])
            ->where('slug', $slug)
            ->first();

        if (! $course) {
            return $this->error('Course not found', 404);
        }

        $data = $this->formatter->formatCourse($course, true);

        $data['sections'] = $course->sections->map(function ($section) {
            return [
                'id' => $section->id,
                'title' => $section->title,
                'description' => $section->description,
                'sort_order' => $section->sort_order,
                'lessons' => $section->lessons
                    ->where('is_published', true)
                    ->values()
                    ->map(fn (Lesson $lesson) => $this->formatter->formatLesson($lesson, null, null, false))
                    ->all(),
            ];
        })->values()->all();

        $data['quizzes'] = $course->quizzes()
            ->where('is_published', true)
            ->get(['id', 'title', 'lesson_id', 'pass_percentage', 'time_limit_minutes', 'max_attempts'])
            ->toArray();

        $data['reviews_summary'] = $this->formatter->reviewSummary($course);

        return $this->success('Course retrieved successfully', $data);
    }

    public function adminIndex(Request $request)
    {
        $query = Course::query()->with('category');

        if ($request->filled('search')) {
            $term = '%'.$request->search.'%';
            $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('slug', 'like', $term));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('category_id')) {
            $query->where('course_category_id', $request->category_id);
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $courses = $query->latest()->paginate($perPage)->withQueryString();
        $courses->setCollection(
            $courses->getCollection()->map(fn (Course $course) => $this->formatter->formatCourse($course))
        );

        return $this->success('Courses retrieved successfully', $courses);
    }

    public function adminShow($id)
    {
        $course = Course::query()
            ->with([
                'category',
                'sections.lessons.quiz',
                'quizzes.questions',
            ])
            ->find($id);

        if (! $course) {
            return $this->error('Course not found', 404);
        }

        $data = $this->formatter->formatCourse($course, true);

        $data['sections'] = $course->sections->map(function ($section) {
            return [
                'id' => $section->id,
                'title' => $section->title,
                'description' => $section->description,
                'sort_order' => $section->sort_order,
                'lessons' => $section->lessons
                    ->map(fn (Lesson $lesson) => $this->formatter->formatAdminLesson($lesson))
                    ->values()
                    ->all(),
            ];
        })->values()->all();

        $data['quizzes'] = $course->quizzes->map(fn ($quiz) => [
            'id' => $quiz->id,
            'course_id' => $quiz->course_id,
            'lesson_id' => $quiz->lesson_id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'pass_percentage' => $quiz->pass_percentage,
            'time_limit_minutes' => $quiz->time_limit_minutes,
            'max_attempts' => $quiz->max_attempts,
            'is_published' => $quiz->is_published,
            'sort_order' => $quiz->sort_order,
            'questions' => $quiz->questions->toArray(),
        ])->values()->all();

        $data['reviews_summary'] = $this->formatter->reviewSummary($course);

        return $this->success('Course retrieved successfully', $data);
    }

    public function store(Request $request)
    {
        $request->validate([
            'course_category_id' => 'nullable|exists:course_categories,id',
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:courses,slug',
            'subtitle' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'thumbnail' => 'nullable',
            'promo_video_url' => 'nullable|url',
            'level' => 'nullable|in:all,beginner,intermediate,advanced',
            'language' => 'nullable|string|max:50',
            'price' => 'nullable|numeric|min:0',
            'offer_price' => 'nullable|numeric|min:0',
            'access_days' => 'nullable|integer|min:0',
            'status' => 'nullable|in:draft,published,archived',
            'is_featured' => 'nullable|boolean',
            'requirements' => 'nullable|array',
            'outcomes' => 'nullable|array',
        ]);

        $status = $request->input('status', 'draft');

        $course = Course::create([
            'course_category_id' => $request->course_category_id,
            'title' => $request->title,
            'slug' => $request->filled('slug') ? Str::slug($request->slug) : Course::uniqueSlug($request->title),
            'subtitle' => $request->subtitle,
            'description' => $request->description,
            'thumbnail' => $this->resolveThumbnail($request),
            'promo_video_url' => $request->promo_video_url,
            'level' => $request->input('level', 'all'),
            'language' => $request->input('language', 'en'),
            'price' => $request->input('price', 0),
            'offer_price' => $request->offer_price,
            'access_days' => $request->has('access_days') ? (int) $request->access_days : null,
            'status' => $status,
            'is_featured' => $request->boolean('is_featured'),
            'requirements' => $request->requirements,
            'outcomes' => $request->outcomes,
            'published_at' => $status === 'published' ? now() : null,
        ]);

        return $this->success(
            'Course created successfully',
            $this->formatter->formatCourse($course->fresh('category'))
        );
    }

    public function update(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:courses,id',
            'course_category_id' => 'nullable|exists:course_categories,id',
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:courses,slug,'.$request->id,
            'subtitle' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'thumbnail' => 'nullable',
            'promo_video_url' => 'nullable|url',
            'level' => 'nullable|in:all,beginner,intermediate,advanced',
            'language' => 'nullable|string|max:50',
            'price' => 'nullable|numeric|min:0',
            'offer_price' => 'nullable|numeric|min:0',
            'access_days' => 'nullable|integer|min:0',
            'status' => 'nullable|in:draft,published,archived',
            'is_featured' => 'nullable|boolean',
            'requirements' => 'nullable|array',
            'outcomes' => 'nullable|array',
        ]);

        $course = Course::findOrFail($request->id);
        $wasPublished = $course->published_at !== null;

        $payload = [
            'course_category_id' => $request->has('course_category_id') ? $request->course_category_id : $course->course_category_id,
            'title' => $request->title,
            'slug' => $request->filled('slug') ? Str::slug($request->slug) : $course->slug,
            'subtitle' => $request->subtitle,
            'description' => $request->description,
            'thumbnail' => $this->resolveThumbnail($request, $course->thumbnail),
            'promo_video_url' => $request->promo_video_url,
            'level' => $request->input('level', $course->level),
            'language' => $request->input('language', $course->language),
            'price' => $request->input('price', $course->price),
            'offer_price' => $request->has('offer_price') ? $request->offer_price : $course->offer_price,
            'access_days' => $request->has('access_days') ? (int) $request->access_days : $course->access_days,
            'status' => $request->input('status', $course->status),
            'is_featured' => $request->has('is_featured') ? $request->boolean('is_featured') : $course->is_featured,
            'requirements' => $request->has('requirements') ? $request->requirements : $course->requirements,
            'outcomes' => $request->has('outcomes') ? $request->outcomes : $course->outcomes,
        ];

        if ($payload['status'] === 'published' && ! $wasPublished) {
            $payload['published_at'] = now();
        }

        $course->update($payload);

        return $this->success(
            'Course updated successfully',
            $this->formatter->formatCourse($course->fresh('category'))
        );
    }

    public function destroy($id)
    {
        $course = Course::find($id);

        if (! $course) {
            return $this->error('Course not found', 404);
        }

        $course->delete();

        return $this->success('Course deleted successfully', null);
    }

    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $term = '%'.$request->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('subtitle', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        if ($request->filled('category')) {
            $category = $request->category;
            $query->whereHas('category', function ($q) use ($category) {
                $q->where('slug', $category)->orWhere('id', $category);
            });
        }

        if ($request->filled('level')) {
            $query->where('level', $request->level);
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        switch ($request->query('sort', 'latest')) {
            case 'price_asc':
                $query->orderByRaw('COALESCE(offer_price, price) asc');
                break;
            case 'price_desc':
                $query->orderByRaw('COALESCE(offer_price, price) desc');
                break;
            case 'rating':
                $query->orderByDesc('rating_avg')->orderByDesc('rating_count');
                break;
            case 'popular':
                $query->orderByDesc('total_students');
                break;
            default:
                $query->latest('published_at')->latest('id');
        }
    }

    private function resolveThumbnail(Request $request, ?string $current = null): ?string
    {
        if ($request->hasFile('thumbnail')) {
            $directory = public_path('images/courses');

            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            $file = $request->file('thumbnail');
            $name = time().'-'.uniqid().'.'.$file->extension();
            $file->move($directory, $name);

            return 'images/courses/'.$name;
        }

        if ($request->filled('thumbnail')) {
            return $request->thumbnail;
        }

        return $current;
    }
}
