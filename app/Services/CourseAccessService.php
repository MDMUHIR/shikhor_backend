<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Order;
use App\Models\QuizAttempt;
use App\Notifications\LmsNotification;
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
            && in_array($enrollment->status, ['active', 'completed'], true)
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
            $course->refreshStudentCount();
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
            ->each(fn (Enrollment $enrollment) => $enrollment->forceFill(['status' => 'cancelled'])->save());
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
                if ($existing->status === 'cancelled'
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

    /**
     * @throws \DomainException when the lesson has not been watched enough
     */
    public function markLessonComplete(User $user, Lesson $lesson, ?int $position = null, bool $enforceThreshold = true): LessonProgress
    {
        $progress = LessonProgress::firstOrNew([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
        ]);

        if ($enforceThreshold
            && $lesson->isVideo()
            && $lesson->completion_threshold
            && $lesson->duration_seconds > 0
            && $progress->completed_at === null) {
            $needed = (int) ceil($lesson->duration_seconds * $lesson->completion_threshold / 100);

            if (($progress->max_position_seconds ?? 0) < $needed) {
                throw new \DomainException('Watch more of this lesson before marking it complete');
            }
        }

        $progress->course_id = $lesson->course_id;
        $progress->completed_at = $progress->completed_at ?? Carbon::now();
        $progress->started_at = $progress->started_at ?? Carbon::now();
        $progress->last_activity_at = Carbon::now();

        if ($position !== null) {
            $this->applyPosition($progress, $lesson, $position);
        }

        $progress->save();

        $this->syncEnrollment($user, $lesson->course, $lesson);

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
        $progress->last_activity_at = Carbon::now();
        $progress->save();

        $this->syncEnrollment($user, $lesson->course, $lesson);

        return $progress;
    }

    public function updateWatchPosition(User $user, Lesson $lesson, int $seconds): LessonProgress
    {
        $progress = LessonProgress::firstOrNew([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
        ]);

        $progress->course_id = $lesson->course_id;
        $progress->started_at = $progress->started_at ?? Carbon::now();

        $previous = (int) ($progress->last_position_seconds ?? 0);
        $elapsed = $progress->last_activity_at
            ? (int) abs($progress->last_activity_at->diffInSeconds(Carbon::now()))
            : 0;

        $this->applyPosition($progress, $lesson, $seconds);

        // Credit watch time only for forward movement the wall clock could explain.
        $delta = $progress->last_position_seconds - $previous;
        if ($delta > 0 && $delta <= $elapsed + 15) {
            $progress->watched_seconds = ($progress->watched_seconds ?? 0) + $delta;
        }

        $progress->last_activity_at = Carbon::now();

        if ($lesson->isVideo()
            && $lesson->duration_seconds > 0
            && $progress->completed_at === null) {
            $threshold = $lesson->completion_threshold ?: 95;

            if ($progress->max_position_seconds >= (int) ceil($lesson->duration_seconds * $threshold / 100)) {
                $progress->completed_at = Carbon::now();
            }
        }

        $progress->save();

        $this->syncEnrollment($user, $lesson->course, $lesson);

        return $progress;
    }

    private function applyPosition(LessonProgress $progress, Lesson $lesson, int $seconds): void
    {
        $seconds = max(0, $seconds);

        if ($lesson->duration_seconds > 0) {
            $seconds = min($seconds, $lesson->duration_seconds);
        }

        $progress->last_position_seconds = $seconds;
        $progress->max_position_seconds = max((int) ($progress->max_position_seconds ?? 0), $seconds);
    }

    /**
     * Whether the student meets every completion rule (required lessons, % required, quizzes).
     */
    public function isCourseComplete(User $user, Course $course): bool
    {
        $summary = $this->progress($user, $course);

        if ($summary['total_lessons'] === 0) {
            return false;
        }

        $required = $course->publishedLessons()->where('is_required', true)->pluck('id');
        $completed = collect($summary['completed_lesson_ids']);

        if ($required->diff($completed)->isNotEmpty()) {
            return false;
        }

        if ($summary['percentage'] < (float) ($course->completion_percentage_required ?? 100)) {
            return false;
        }

        if ($course->require_quizzes_passed) {
            $quizIds = $course->quizzes()->where('is_published', true)->where('is_required', true)->pluck('id');

            if ($quizIds->isNotEmpty()) {
                $passed = QuizAttempt::query()
                    ->where('user_id', $user->id)
                    ->whereIn('quiz_id', $quizIds)
                    ->where('passed', true)
                    ->distinct()
                    ->pluck('quiz_id');

                if ($quizIds->diff($passed)->isNotEmpty()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Refresh cached enrollment progress, flag completion and auto-issue the certificate.
     */
    public function syncEnrollment(User $user, Course $course, ?Lesson $lastLesson = null): void
    {
        $enrollment = $this->enrollment($user, $course);

        if (! $enrollment || ! $enrollment->isActive()) {
            return;
        }

        $summary = $this->progress($user, $course);
        $complete = $this->isCourseComplete($user, $course);

        $enrollment->progress_percentage = $summary['percentage'];
        $enrollment->last_accessed_at = Carbon::now();

        if ($lastLesson) {
            $enrollment->last_lesson_id = $lastLesson->id;
        }

        $enrollment->certificate_eligible = $complete && $course->certificate_enabled;

        if ($complete) {
            $enrollment->completed_at = $enrollment->completed_at ?? Carbon::now();
            $enrollment->status = 'completed';
        } elseif ($enrollment->status === 'completed') {
            $enrollment->status = 'active';
            $enrollment->completed_at = null;
        }

        $enrollment->save();

        if ($enrollment->certificate_eligible) {
            $certificate = app(CertificateService::class)->issue($user, $course);

            if ($certificate->wasRecentlyCreated) {
                $user->notify(new LmsNotification(
                    'certificate_issued',
                    'Certificate issued',
                    'Your certificate for "'.$course->title.'" is ready.',
                    ['certificate_number' => $certificate->certificate_number, 'course_id' => $course->id]
                ));
            }
        }
    }

    public function canIssueCertificate(User $user, Course $course): bool
    {
        return $course->certificate_enabled && $this->isCourseComplete($user, $course);
    }
}
