<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Services\ExamService;
use Illuminate\Http\Request;

/** Student-facing exam API. */
class ExamController extends Controller
{
    public function __construct(private readonly ExamService $exams)
    {
    }

    /** Published exams the student may see (standalone ones + exams of their enrolled courses). */
    public function index(Request $request)
    {
        $user = $request->user();

        $courseIds = Enrollment::query()->active()->where('user_id', $user->id)->pluck('course_id');

        $exams = Exam::query()
            ->with('course:id,title,slug')
            ->withCount('questions')
            ->withSum('questions', 'marks')
            ->where('is_published', true)
            ->where(fn ($q) => $q->whereNull('course_id')->orWhereIn('course_id', $courseIds))
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->course_id))
            ->orderByRaw('starts_at is null, starts_at desc')
            ->orderByDesc('id')
            ->get();

        $attempts = ExamAttempt::query()
            ->where('user_id', $user->id)
            ->whereIn('exam_id', $exams->pluck('id'))
            ->get()
            ->groupBy('exam_id');

        return $this->success('Exams retrieved successfully', $exams->map(function (Exam $exam) use ($attempts) {
            $mine = $attempts->get($exam->id, collect());
            $finished = $mine->where('status', '!=', 'in_progress');
            $live = $mine->firstWhere('status', 'in_progress');

            return $this->format($exam) + [
                'attempts_used' => $mine->count(),
                'best_percentage' => $finished->isNotEmpty() ? (float) $finished->max('percentage') : null,
                'passed' => $finished->contains('passed', true),
                'in_progress_attempt_id' => $live && $live->expires_at->isFuture() ? $live->id : null,
            ];
        })->values());
    }

    public function show(Request $request, $id)
    {
        $exam = Exam::with('course:id,title,slug')->withCount('questions')->withSum('questions', 'marks')->find($id);

        if (! $exam || ! $exam->is_published) {
            return $this->notFound('Exam not found');
        }

        $user = $request->user();
        $reason = $this->exams->canTake($user, $exam);
        $mine = ExamAttempt::where('exam_id', $exam->id)->where('user_id', $user->id)->latest('id')->get();
        $live = $mine->firstWhere('status', 'in_progress');

        return $this->success('Exam retrieved successfully', $this->format($exam) + [
            'can_take' => $reason === null,
            'blocked_reason' => $reason,
            'attempts_used' => $mine->count(),
            'attempts_left' => $exam->max_attempts === null ? null : max($exam->max_attempts - $mine->count(), 0),
            'in_progress_attempt_id' => $live && $live->expires_at->isFuture() ? $live->id : null,
            'attempts' => $mine->where('status', '!=', 'in_progress')->map(fn (ExamAttempt $a) => $this->exams->attemptSummary($a))->values(),
        ]);
    }

    public function start(Request $request, $id)
    {
        $exam = Exam::find($id);

        if (! $exam || ! $exam->is_published) {
            return $this->notFound('Exam not found');
        }

        try {
            $attempt = $this->exams->start($exam, $request->user());
        } catch (\DomainException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('Exam started', $this->attemptState($attempt, $exam));
    }

    /** In progress: questions + saved answers. Finished: the result. */
    public function attempt(Request $request, $id)
    {
        $attempt = $this->ownAttempt($request, $id);

        if (! $attempt) {
            return $this->notFound('Attempt not found');
        }

        if ($attempt->status === 'in_progress' && $attempt->expires_at->isPast()) {
            $attempt = $this->exams->finalize($attempt, 'expired');
        }

        $exam = $attempt->exam;

        if ($attempt->isFinished()) {
            return $this->success('Result retrieved successfully', $this->exams->resultPayload($attempt, $exam) + [
                'exam' => $this->format($exam),
            ]);
        }

        return $this->success('Attempt retrieved successfully', $this->attemptState($attempt, $exam));
    }

    /** Autosave. */
    public function saveAnswers(Request $request, $id)
    {
        $request->validate(['answers' => 'present|array']);

        $attempt = $this->ownAttempt($request, $id);

        if (! $attempt) {
            return $this->notFound('Attempt not found');
        }

        $attempt = $this->exams->saveAnswers($attempt, $request->input('answers', []));

        return $this->success('Answers saved', [
            'status' => $attempt->status,
            'saved_count' => count($attempt->answers ?? []),
            'seconds_left' => $this->secondsLeft($attempt),
        ]);
    }

    public function submit(Request $request, $id)
    {
        $request->validate(['answers' => 'nullable|array']);

        $attempt = $this->ownAttempt($request, $id);

        if (! $attempt) {
            return $this->notFound('Attempt not found');
        }

        $attempt = $this->exams->submit($attempt, $request->has('answers') ? $request->input('answers') : null);

        return $this->success('Exam submitted', $this->exams->resultPayload($attempt->load('exam'), $attempt->exam) + [
            'exam' => $this->format($attempt->exam),
        ]);
    }

    public function myAttempts(Request $request, $id)
    {
        $attempts = ExamAttempt::where('exam_id', $id)->where('user_id', $request->user()->id)->latest('id')->get();

        return $this->success('Attempts retrieved successfully', $attempts->map(fn ($a) => $this->exams->attemptSummary($a))->values());
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function ownAttempt(Request $request, $id): ?ExamAttempt
    {
        return ExamAttempt::with('exam')->where('user_id', $request->user()->id)->find($id);
    }

    private function secondsLeft(ExamAttempt $attempt): int
    {
        return $attempt->isFinished() ? 0 : max(0, (int) now()->diffInSeconds($attempt->expires_at, false));
    }

    private function attemptState(ExamAttempt $attempt, Exam $exam): array
    {
        return [
            'attempt' => $this->exams->attemptSummary($attempt),
            'exam' => $this->format($exam->loadCount('questions')),
            'questions' => $this->exams->questionsForAttempt($attempt, $exam),
            'answers' => (object) ($attempt->answers ?? []),
            'seconds_left' => $this->secondsLeft($attempt),
            'server_time' => now()->toIso8601String(),
        ];
    }

    private function format(Exam $exam): array
    {
        return [
            'id' => $exam->id,
            'title' => $exam->title,
            'description' => $exam->description,
            'instructions' => $exam->instructions,
            'course' => $exam->relationLoaded('course') && $exam->course
                ? ['id' => $exam->course->id, 'title' => $exam->course->title, 'slug' => $exam->course->slug]
                : null,
            'duration_minutes' => $exam->duration_minutes,
            'pass_percentage' => $exam->pass_percentage,
            'max_attempts' => $exam->max_attempts,
            'questions_count' => (int) ($exam->questions_count ?? 0),
            'total_marks' => (float) ($exam->questions_sum_marks ?? 0),
            'negative_marking' => $exam->negative_marking,
            'negative_marking_type' => $exam->negative_marking ? $exam->negative_marking_type : null,
            'negative_marking_value' => $exam->negative_marking ? $exam->negative_marking_value : 0,
            'allow_negative_total' => $exam->allow_negative_total,
            'show_answers' => $exam->show_answers,
            'starts_at' => $exam->starts_at?->toIso8601String(),
            'ends_at' => $exam->ends_at?->toIso8601String(),
            'state' => $exam->windowState(),
        ];
    }
}
