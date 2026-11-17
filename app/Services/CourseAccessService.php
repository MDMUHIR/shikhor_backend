<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class CourseAccessService
{
    /**
     * Get a user's enrollment for a course, marking it expired when needed.
     */
    public function enrollment(User $user, Course $course): ?Enrollment
    {
        $enrollment = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if ($enrollment
            && $enrollment->status === 'active'
            && $enrollment->expires_at !== null
            && $enrollment->expires_at->isPast()) {
            $enrollment->forceFill(['status' => 'expired'])->save();
        }

        return $enrollment;
    }

    public function hasActiveAccess(User $user, Course $course): bool
    {
        $enrollment = $this->enrollment($user, $course);

        return $enrollment !== null && $enrollment->isActive();
    }

    public function canAccessLesson(User $user, Lesson $lesson): bool
    {
        if (! $lesson->is_published) {
            return false;
        }

        if ($lesson->is_preview) {
            return true;
        }

        return $this->hasActiveAccess($user, $lesson->course);
    }

    /**
     * Grant (or extend) access to a course.
     *
     * @param  int|null  $accessDays  null = course/config default, 0 = lifetime
     */
    public function enroll(
        User $user,
        Course $course,
        ?Order $order = null,
        ?int $accessDays = null,
        ?CarbonInterface $expiresAt = null,
        string $source = 'order'
    ): Enrollment {
        $enrollment = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        $isNew = $enrollment === null;

        if ($isNew) {
            $enrollment = new Enrollment([
                'user_id' => $user->id,
                'course_id' => $course->id,
            ]);
        }

        $days = $accessDays !== null
            ? $accessDays
            : ($course->access_days !== null
                ? (int) $course->access_days
                : (int) config('courses.default_access_days', 365));

        $lifetime = $expiresAt === null && $days === 0;

        if ($expiresAt === null && ! $lifetime) {
            $base = $enrollment->expires_at !== null && $enrollment->expires_at->isFuture()
                ? $enrollment->expires_at->copy()
                : Carbon::now();

            $expiresAt = $base->addDays($days);
        }

        $enrollment->fill([
            'order_id' => $order?->id ?? $enrollment->order_id,
            'source' => $source,
            'status' => 'active',
            'enrolled_at' => $enrollment->enrolled_at ?? Carbon::now(),
            'starts_at' => Carbon::now(),
            'expires_at' => $expiresAt,
        ])->save();

        if ($isNew) {
            $course->increment('total_students');
        }

        return $enrollment->refresh();
    }

    /**
     * Revoke enrollments that were granted by an order (manual grants are kept).
     */
    public function revokeForOrder(Order $order): void
    {
        Enrollment::query()
            ->where('order_id', $order->id)
            ->where('source', 'order')
            ->get()
            ->each(fn (Enrollment $enrollment) => $enrollment->forceFill(['status' => 'revoked'])->save());
    }

    /**
     * Ensure every course in an order has an enrollment for the buyer.
     * Safe to call more than once (it will not extend access twice).
     */
    public function ensureEnrollmentsForOrder(Order $order): void
    {
        $order->loadMissing('courseItems.course', 'user');

        foreach ($order->courseItems as $item) {
            if (! $item->course || ! $order->user) {
                continue;
            }

            $existing = Enrollment::query()
                ->where('user_id', $order->user_id)
                ->where('course_id', $item->course_id)
                ->first();

            if ($existing && (int) $existing->order_id === (int) $order->id) {
                // Re-activate access that was revoked by a cancellation/refund.
                if ($existing->status === 'revoked'
                    && ($existing->expires_at === null || $existing->expires_at->isFuture())) {
                    $existing->forceFill(['status' => 'active'])->save();
                }

                continue;
            }

            // The student already has active access (manual grant or another order).
            if ($existing && $existing->isActive()) {
                continue;
            }

            $this->enroll(
                $order->user,
                $item->course,
                $order,
                $item->access_days,
                null,
                'order'
            );
        }
    }

    /**
     * Course progress summary for a student.
     */
    public function progress(User $user, Course $course): array
    {
        $lessonIds = $course->publishedLessons()->pluck('id');

        $completedIds = LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereNotNull('completed_at')
            ->whereIn('lesson_id', $lessonIds)
            ->pluck('lesson_id')
            ->unique();

        $total = $lessonIds->count();
        $completed = $completedIds->count();
        $percentage = $total > 0 ? round(($completed / $total) * 100, 2) : 0.0;

        $enrollment = $this->enrollment($user, $course);

        return [
            'total_lessons' => $total,
            'completed_lessons' => $completed,
            'percentage' => $percentage,
            'is_complete' => $total > 0 && $completed >= $total,
            'completed_lesson_ids' => $completedIds->values()->all(),
            'enrollment' => $enrollment ? [
                'id' => $enrollment->id,
                'status' => $enrollment->status,
                'is_active' => $enrollment->isActive(),
                'expires_at' => $enrollment->expires_at?->toIso8601String(),
                'remaining_days' => $enrollment->remainingDays(),
            ] : null,
        ];
    }

    public function markLessonComplete(User $user, Lesson $lesson, ?int $position = null): LessonProgress
    {
        $progress = LessonProgress::firstOrNew([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
        ]);

        $progress->course_id = $lesson->course_id;
        $progress->completed_at = Carbon::now();

        if ($position !== null) {
            $progress->last_position_seconds = max((int) $position, $progress->last_position_seconds ?? 0);
        }

        $progress->save();

        return $progress;
    }

    public function markLessonIncomplete(User $user, Lesson $lesson): LessonProgress
    {
        $progress = LessonProgress::firstOrNew([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
        ]);

        $progress->course_id = $lesson->course_id;
        $progress->completed_at = null;
        $progress->save();

        return $progress;
    }

    public function updateWatchPosition(User $user, Lesson $lesson, int $seconds): LessonProgress
    {
        $progress = LessonProgress::firstOrNew([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
        ]);

        $progress->course_id = $lesson->course_id;
        $progress->last_position_seconds = max(0, $seconds);

        if (! $lesson->isQuiz()
            && $lesson->duration_seconds > 0
            && $progress->last_position_seconds >= max($lesson->duration_seconds - 5, 0)) {
            $progress->completed_at = $progress->completed_at ?? Carbon::now();
        }

        $progress->save();

        return $progress;
    }

    public function canIssueCertificate(User $user, Course $course): bool
    {
        return $this->progress($user, $course)['is_complete'];
    }
}
