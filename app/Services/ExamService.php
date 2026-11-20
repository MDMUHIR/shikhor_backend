<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Exam lifecycle and automatic marking. All scoring happens here, on the server,
 * from the answers saved in the database - never from client-supplied scores.
 */
class ExamService
{
    /** Seconds after the deadline during which a last submit is still accepted. */
    public const GRACE_SECONDS = 20;

    public function __construct(private readonly CourseAccessService $access)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Access & start
    |--------------------------------------------------------------------------
    */

    public function canTake(User $user, Exam $exam): ?string
    {
        if (! $exam->is_published) {
            return 'This exam is not available';
        }

        if ($exam->course_id && ! $this->access->hasActiveAccess($user, $exam->course)) {
            return 'You need an active enrollment in this course to take the exam';
        }

        return null;
    }

    /**
     * Start an attempt, or resume the live one. Returns [attempt, resumed].
     *
     * @throws \DomainException
     */
    public function start(Exam $exam, User $user): ExamAttempt
    {
        if ($reason = $this->canTake($user, $exam)) {
            throw new \DomainException($reason);
        }

        $live = $this->liveAttempt($exam, $user);

        if ($live) {
            return $live;
        }

        $state = $exam->windowState();

        if ($state === 'upcoming') {
            throw new \DomainException('This exam has not started yet');
        }

        if ($state === 'closed') {
            throw new \DomainException('This exam is closed');
        }

        $questions = $exam->questions()->get();

        if ($questions->isEmpty()) {
            throw new \DomainException('This exam has no questions yet');
        }

        if ($exam->max_attempts !== null) {
            $used = ExamAttempt::where('exam_id', $exam->id)->where('user_id', $user->id)->count();

            if ($used >= $exam->max_attempts) {
                throw new \DomainException('You have used all of your attempts');
            }
        }

        $order = $questions->pluck('id')->all();

        if ($exam->shuffle_questions) {
            shuffle($order);
        }

        $expires = now()->addMinutes($exam->duration_minutes);

        if ($exam->ends_at && $exam->ends_at->lt($expires)) {
            $expires = $exam->ends_at->copy();
        }

        try {
            return ExamAttempt::create([
                'exam_id' => $exam->id,
                'user_id' => $user->id,
                'active_key' => $exam->id.':'.$user->id,
                'status' => 'in_progress',
                'started_at' => now(),
                'expires_at' => $expires,
                'answers' => [],
                'question_order' => $order,
                'settings' => $exam->markingRules(),
                'total_marks' => $questions->sum('marks'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Double click / two tabs: hand back the attempt that won the race.
            return $this->liveAttempt($exam, $user);
        }
    }

    /** The student's in-progress attempt (finalizing it first if the clock ran out). */
    public function liveAttempt(Exam $exam, User $user): ?ExamAttempt
    {
        $attempt = ExamAttempt::where('exam_id', $exam->id)
            ->where('user_id', $user->id)
            ->where('status', 'in_progress')
            ->first();

        if ($attempt && $attempt->expires_at->isPast()) {
            $this->finalize($attempt, 'expired');

            return null;
        }

        return $attempt;
    }

    /*
    |--------------------------------------------------------------------------
    | Answers & submission
    |--------------------------------------------------------------------------
    */

    /**
     * Merge answers into an in-progress attempt (autosave). Unknown questions/options are dropped.
     */
    public function saveAnswers(ExamAttempt $attempt, array $incoming): ExamAttempt
    {
        return DB::transaction(function () use ($attempt, $incoming) {
            $attempt = ExamAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($attempt->isFinished()) {
                return $attempt;
            }

            if ($attempt->expires_at->copy()->addSeconds(self::GRACE_SECONDS)->isPast()) {
                return $this->finalize($attempt, 'expired');
            }

            $questions = ExamQuestion::where('exam_id', $attempt->exam_id)->get()->keyBy('id');
            $answers = $attempt->answers ?? [];

            foreach ($incoming as $questionId => $selected) {
                $question = $questions->get((int) $questionId);

                if (! $question) {
                    continue;
                }

                $selected = is_array($selected) ? $selected : [$selected];
                $validIds = array_map(fn ($option) => (string) ($option['id'] ?? ''), $question->options ?? []);
                $clean = array_values(array_unique(array_filter(
                    array_map('strval', $selected),
                    fn ($value) => $value !== '' && in_array($value, $validIds, true)
                )));

                if ($question->type !== 'multiple_choice') {
                    $clean = array_slice($clean, 0, 1);
                }

                if ($clean === []) {
                    unset($answers[$question->id]);
                } else {
                    $answers[$question->id] = $clean;
                }
            }

            $attempt->forceFill(['answers' => $answers])->save();

            return $attempt;
        });
    }

    /** Final submit: save last answers (if still in time) and grade. Idempotent. */
    public function submit(ExamAttempt $attempt, ?array $incoming = null): ExamAttempt
    {
        if ($incoming !== null && ! $attempt->isFinished()) {
            $attempt = $this->saveAnswers($attempt, $incoming);
        }

        return $this->finalize($attempt, 'submitted');
    }

    /** Grade and close an attempt. Safe to call repeatedly. */
    public function finalize(ExamAttempt $attempt, string $status = 'submitted'): ExamAttempt
    {
        return DB::transaction(function () use ($attempt, $status) {
            $attempt = ExamAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($attempt->isFinished()) {
                return $attempt;
            }

            $questions = ExamQuestion::where('exam_id', $attempt->exam_id)->get();
            $result = $this->grade($questions, $attempt->answers ?? [], $attempt->settings ?? []);

            $attempt->forceFill([
                'status' => $status === 'expired' ? 'expired' : 'submitted',
                'active_key' => null,
                'submitted_at' => now(),
                'score' => $result['score'],
                'total_marks' => $result['total_marks'],
                'percentage' => $result['percentage'],
                'correct_count' => $result['correct'],
                'wrong_count' => $result['wrong'],
                'unanswered_count' => $result['unanswered'],
                'negative_marks_total' => $result['negative_total'],
                'passed' => $result['passed'],
            ])->save();

            return $attempt;
        });
    }

    /** Auto-submit every attempt whose time is up (scheduled command). */
    public function finalizeExpired(): int
    {
        $count = 0;

        ExamAttempt::where('status', 'in_progress')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->each(function (ExamAttempt $attempt) use (&$count) {
                $this->finalize($attempt, 'expired');
                $count++;
            });

        return $count;
    }

    /*
    |--------------------------------------------------------------------------
    | Marking
    |--------------------------------------------------------------------------
    */

    /** Penalty (positive number) deducted for a wrong answer to $question. */
    public function penaltyFor(ExamQuestion $question, array $rules): float
    {
        if (empty($rules['negative_marking'])) {
            return 0.0;
        }

        if ($question->negative_marks !== null) {
            return round((float) $question->negative_marks, 2);
        }

        $value = (float) ($rules['value'] ?? 0);

        return round(($rules['type'] ?? 'fraction') === 'fixed' ? $value : (float) $question->marks * $value, 2);
    }

    /**
     * @param  Collection<int, ExamQuestion>  $questions
     * @return array{score: float, total_marks: float, percentage: float, correct: int, wrong: int, unanswered: int, negative_total: float, passed: bool, lines: array}
     */
    public function grade(Collection $questions, array $answers, array $rules): array
    {
        $total = 0.0;
        $earned = 0.0;
        $negative = 0.0;
        $correct = $wrong = $unanswered = 0;
        $lines = [];

        foreach ($questions as $question) {
            $total += (float) $question->marks;
            $selected = $answers[$question->id] ?? [];

            if ($selected === []) {
                $unanswered++;
                $lines[$question->id] = ['status' => 'unanswered', 'awarded' => 0.0];

                continue;
            }

            if ($question->isCorrect($selected)) {
                $correct++;
                $earned += (float) $question->marks;
                $lines[$question->id] = ['status' => 'correct', 'awarded' => (float) $question->marks];

                continue;
            }

            $wrong++;
            $penalty = $this->penaltyFor($question, $rules);
            $negative += $penalty;
            $lines[$question->id] = ['status' => 'wrong', 'awarded' => -$penalty];
        }

        $score = round($earned - $negative, 2);

        if (empty($rules['allow_negative_total'])) {
            $score = max($score, 0.0);
        }

        $percentage = $total > 0 ? round(max($score, 0) / $total * 100, 2) : 0.0;

        return [
            'score' => $score,
            'total_marks' => round($total, 2),
            'percentage' => $percentage,
            'correct' => $correct,
            'wrong' => $wrong,
            'unanswered' => $unanswered,
            'negative_total' => round($negative, 2),
            'passed' => $percentage >= (float) ($rules['pass_percentage'] ?? 50),
            'lines' => $lines,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    /** Questions for taking the exam, in this attempt's order, WITHOUT answers. */
    public function questionsForAttempt(ExamAttempt $attempt, Exam $exam): array
    {
        $questions = $exam->questions()->get()->keyBy('id');
        $order = $attempt->question_order ?: $questions->keys()->all();
        $out = [];

        foreach ($order as $id) {
            $question = $questions->get($id);

            if (! $question) {
                continue;
            }

            $options = $question->options ?? [];

            if ($exam->shuffle_options) {
                usort($options, fn ($a, $b) => crc32($attempt->id.'-'.$question->id.'-'.($a['id'] ?? '')) <=> crc32($attempt->id.'-'.$question->id.'-'.($b['id'] ?? '')));
            }

            $out[] = [
                'id' => $question->id,
                'type' => $question->type,
                'question' => $question->question,
                'options' => array_map(fn ($o) => ['id' => (string) $o['id'], 'text' => $o['text'] ?? ''], $options),
                'marks' => $question->marks,
                'negative_marks' => $this->penaltyFor($question, $attempt->settings ?? []),
            ];
        }

        return $out;
    }

    public function canRevealAnswers(Exam $exam, ExamAttempt $attempt): bool
    {
        if (! $attempt->isFinished()) {
            return false;
        }

        return match ($exam->show_answers) {
            'never' => false,
            'after_exam_end' => ! $exam->ends_at || $exam->ends_at->isPast(),
            default => true,
        };
    }

    /** Result with a per-question review. Correct answers only when the exam allows it. */
    public function resultPayload(ExamAttempt $attempt, Exam $exam): array
    {
        $questions = $exam->questions()->get();
        $graded = $this->grade($questions, $attempt->answers ?? [], $attempt->settings ?? []);
        $reveal = $this->canRevealAnswers($exam, $attempt);

        $review = $questions->map(function (ExamQuestion $question) use ($attempt, $graded, $reveal) {
            $line = $graded['lines'][$question->id];

            return [
                'question_id' => $question->id,
                'question' => $question->question,
                'options' => $question->options,
                'marks' => $question->marks,
                'selected' => $attempt->answers[$question->id] ?? [],
                'status' => $line['status'],
                'awarded' => $line['awarded'],
                'correct_answers' => $reveal ? $question->correct_answers : null,
                'explanation' => $reveal ? $question->explanation : null,
            ];
        })->values()->all();

        return [
            'attempt' => $this->attemptSummary($attempt),
            'answers_visible' => $reveal,
            'review' => $review,
        ];
    }

    public function attemptSummary(ExamAttempt $attempt): array
    {
        $rules = $attempt->settings ?? [];

        return [
            'id' => $attempt->id,
            'exam_id' => $attempt->exam_id,
            'status' => $attempt->status,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'expires_at' => $attempt->expires_at?->toIso8601String(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'score' => $attempt->isFinished() ? (float) $attempt->score : null,
            'total_marks' => (float) $attempt->total_marks,
            'percentage' => $attempt->isFinished() ? (float) $attempt->percentage : null,
            'correct_count' => $attempt->correct_count,
            'wrong_count' => $attempt->wrong_count,
            'unanswered_count' => $attempt->unanswered_count,
            'negative_marks_total' => (float) $attempt->negative_marks_total,
            'passed' => $attempt->isFinished() ? $attempt->passed : null,
            'negative_marking' => (bool) ($rules['negative_marking'] ?? false),
            'pass_percentage' => $rules['pass_percentage'] ?? null,
        ];
    }
}
