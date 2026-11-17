<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Support\Facades\URL;

class CourseFormatter
{
    public function formatCourse(Course $course, bool $includeCurriculum = false): array
    {
        $data = [
            'id' => $course->id,
            'category_id' => $course->course_category_id,
            'title' => $course->title,
            'slug' => $course->slug,
            'subtitle' => $course->subtitle,
            'description' => $course->description,
            'thumbnail' => $course->thumbnail,
            'promo_video_url' => $course->promo_video_url,
            'level' => $course->level,
            'language' => $course->language,
            'price' => (float) $course->price,
            'offer_price' => $course->offer_price !== null ? (float) $course->offer_price : null,
            'effective_price' => $course->effective_price,
            'access_days' => $course->access_days,
            'effective_access_days' => $course->access_days ?? (int) config('courses.default_access_days', 365),
            'access_type' => $course->access_days === 0 ? 'lifetime' : 'time_limited',
            'status' => $course->status,
            'is_featured' => $course->is_featured,
            'duration_minutes' => $course->duration_minutes,
            'requirements' => $course->requirements ?? [],
            'outcomes' => $course->outcomes ?? [],
            'rating' => [
                'average' => (float) $course->rating_avg,
                'count' => (int) $course->rating_count,
            ],
            'total_lessons' => $course->total_lessons,
            'total_students' => $course->total_students,
            'published_at' => $course->published_at?->toIso8601String(),
            'category' => $course->relationLoaded('category') && $course->category ? [
                'id' => $course->category->id,
                'name' => $course->category->name,
                'slug' => $course->category->slug,
            ] : null,
            'created_at' => $course->created_at?->toIso8601String(),
            'updated_at' => $course->updated_at?->toIso8601String(),
        ];

        if ($includeCurriculum) {
            $data['sections'] = [];
            $data['quizzes'] = [];
        }

        return $data;
    }

    public function formatLesson(
        Lesson $lesson,
        ?User $user = null,
        ?LessonProgress $progress = null,
        bool $hasAccess = false
    ): array {
        $data = [
            'id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'section_id' => $lesson->course_section_id,
            'title' => $lesson->title,
            'slug' => $lesson->slug,
            'description' => $lesson->description,
            'type' => $lesson->type,
            'duration_seconds' => $lesson->duration_seconds,
            'is_preview' => $lesson->is_preview,
            'is_published' => $lesson->is_published,
            'sort_order' => $lesson->sort_order,
            'is_locked' => ! $hasAccess && ! $lesson->is_preview,
            'is_completed' => $progress?->completed_at !== null,
            'last_position_seconds' => (int) ($progress?->last_position_seconds ?? 0),
        ];

        if ($lesson->type === 'text' && ($hasAccess || $lesson->is_preview)) {
            $data['content'] = $lesson->content;
        }

        if ($lesson->type === 'video' && ($hasAccess || $lesson->is_preview)) {
            $data['video'] = $this->videoPayload($lesson, $user, $hasAccess);
        }

        if ($lesson->isQuiz()) {
            $quiz = $lesson->relationLoaded('quiz') ? $lesson->quiz : $lesson->quiz()->first();
            $data['quiz'] = $quiz ? [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'pass_percentage' => $quiz->pass_percentage,
                'time_limit_minutes' => $quiz->time_limit_minutes,
                'max_attempts' => $quiz->max_attempts,
            ] : null;
        }

        return $data;
    }

    public function formatAdminLesson(Lesson $lesson): array
    {
        return [
            'id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'section_id' => $lesson->course_section_id,
            'title' => $lesson->title,
            'slug' => $lesson->slug,
            'description' => $lesson->description,
            'type' => $lesson->type,
            'video_source' => $lesson->video_source,
            'video_url' => $lesson->video_url,
            'video_path' => $lesson->video_path,
            'duration_seconds' => $lesson->duration_seconds,
            'content' => $lesson->content,
            'is_preview' => $lesson->is_preview,
            'is_published' => $lesson->is_published,
            'sort_order' => $lesson->sort_order,
            'quiz' => $lesson->quiz ? [
                'id' => $lesson->quiz->id,
                'title' => $lesson->quiz->title,
                'is_published' => $lesson->quiz->is_published,
            ] : null,
            'created_at' => $lesson->created_at?->toIso8601String(),
            'updated_at' => $lesson->updated_at?->toIso8601String(),
        ];
    }

    public function videoPayload(Lesson $lesson, ?User $user = null, bool $hasAccess = false): ?array
    {
        if ($lesson->video_source === 'external' && $lesson->video_url) {
            return [
                'source' => 'external',
                'url' => $lesson->video_url,
            ];
        }

        if ($lesson->video_source === 'upload' && $lesson->video_path) {
            $params = ['lesson' => $lesson->id];

            if ($user !== null && $hasAccess) {
                $params['user'] = $user->id;
            }

            return [
                'source' => 'upload',
                'stream_url' => URL::temporarySignedRoute(
                    'lessons.stream',
                    now()->addMinutes((int) config('courses.video_stream_url_ttl', 60)),
                    $params
                ),
            ];
        }

        return null;
    }

    public function reviewSummary(Course $course): array
    {
        $reviews = $course->reviews()->where('is_approved', true);

        return [
            'average' => round((float) (clone $reviews)->avg('rating'), 2),
            'count' => (clone $reviews)->count(),
            'five_star' => (clone $reviews)->where('rating', 5)->count(),
            'four_star' => (clone $reviews)->where('rating', 4)->count(),
            'three_star' => (clone $reviews)->where('rating', 3)->count(),
            'two_star' => (clone $reviews)->where('rating', 2)->count(),
            'one_star' => (clone $reviews)->where('rating', 1)->count(),
        ];
    }
}
