<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Services\CourseAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class QuizController extends Controller
{
    public function __construct(private readonly CourseAccessService $access)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Student
    |--------------------------------------------------------------------------
    */

    public function show(Request $request, $id)
    {
        $quiz = Quiz::with(['questions', 'course'])->find($id);

        if (! $quiz || ! $quiz->is_published) {
            return $this->error('Quiz not found', 404);
        }

        if (! $this->access->hasActiveAccess($request->user(), $quiz->course)) {
            return $this->error('You do not have access to this quiz');
        }

        $attempts = QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $request->user()->id)
            ->whereNotNull('completed_at');

        return $this->success('Quiz retrieved successfully', [
            'id' => $quiz->id,
            'course_id' => $quiz->course_id,
            'lesson_id' => $quiz->lesson_id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'pass_percentage' => $quiz->pass_percentage,
            'time_limit_minutes' => $quiz->time_limit_minutes,
            'max_attempts' => $quiz->max_attempts,
            'questions_count' => $quiz->questions->count(),
            'total_marks' => (int) $quiz->questions->sum('marks'),
            'attempts_used' => (clone $attempts)->count(),
            'attempts_remaining' => $quiz->max_attempts !== null
                ? max(0, $quiz->max_attempts - (clone $attempts)->count())
                : null,
            'best_percentage' => (float) ((clone $attempts)->max('percentage') ?? 0),
            'has_passed' => (clone $attempts)->where('passed', true)->exists(),
            'questions' => $quiz->questions->map(fn (QuizQuestion $question) => [
                'id' => $question->id,
                'type' => $question->type,
                'question' => $question->question,
                'options' => $question->options,
                'marks' => $question->marks,
                'sort_order' => $question->sort_order,
            ])->values()->all(),
        ]);
    }

    public function submit(Request $request, $id)
    {
        $request->validate([
            'answers' => 'required|array',
        ]);

        $quiz = Quiz::with(['questions', 'course', 'lesson'])->find($id);

        if (! $quiz || ! $quiz->is_published) {
            return $this->error('Quiz not found', 404);
        }

        $user = $request->user();

        if (! $this->access->hasActiveAccess($user, $quiz->course)) {
            return $this->error('You do not have access to this quiz');
        }

        $completedAttempts = QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $user->id)
            ->whereNotNull('completed_at')
            ->count();

        if ($quiz->max_attempts !== null && $completedAttempts >= $quiz->max_attempts) {
            return $this->error('You have used all of your attempts for this quiz');
        }

        $enrollment = $this->access->enrollment($user, $quiz->course);

        $questions = $quiz->questions;
        $totalMarks = (int) $questions->sum('marks');
        $score = 0;
        $review = [];
        $normalizedAnswers = [];

        foreach ($questions as $question) {
            $selected = $request->input('answers.'.$question->id, []);
            $selected = is_array($selected) ? $selected : [$selected];
            $selected = array_values(array_filter(array_map('strval', $selected), fn ($value) => $value !== ''));

            $normalizedAnswers[$question->id] = $selected;
            $isCorrect = $question->isCorrect($selected);

            if ($isCorrect) {
                $score += (int) $question->marks;
            }

            $review[] = [
                'question_id' => $question->id,
                'selected' => $selected,
                'is_correct' => $isCorrect,
                'correct_answers' => $question->correct_answers,
                'marks' => (int) $question->marks,
            ];
        }

        $percentage = $totalMarks > 0 ? round(($score / $totalMarks) * 100, 2) : 0.0;
        $passed = $percentage >= (float) $quiz->pass_percentage;

        $attempt = DB::transaction(function () use ($quiz, $user, $enrollment, $score, $totalMarks, $percentage, $passed, $normalizedAnswers) {
            return QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'user_id' => $user->id,
                'enrollment_id' => $enrollment?->id,
                'score' => $score,
                'total_marks' => $totalMarks,
                'percentage' => $percentage,
                'passed' => $passed,
                'answers' => $normalizedAnswers,
                'started_at' => Carbon::now(),
                'completed_at' => Carbon::now(),
            ]);
        });

        if ($passed && $quiz->lesson) {
            $this->access->markLessonComplete($user, $quiz->lesson, null, false);
        } else {
            $this->access->syncEnrollment($user, $quiz->course, $quiz->lesson);
        }

        // Do not hand out the answer key until the student has passed or has no attempts left.
        $attemptsLeft = $quiz->max_attempts === null ? null : max($quiz->max_attempts - ($completedAttempts + 1), 0);

        if (! $passed && $attemptsLeft !== 0) {
            $review = array_map(function ($row) {
                unset($row['correct_answers']);

                return $row;
            }, $review);
        }

        return $this->success('Quiz submitted successfully', [
            'attempt' => $this->formatAttempt($attempt),
            'passed' => $passed,
            'attempts_left' => $attemptsLeft,
            'review' => $review,
            'progress' => $this->access->progress($user, $quiz->course),
        ]);
    }

    public function attempts(Request $request, $id)
    {
        $quiz = Quiz::find($id);

        if (! $quiz) {
            return $this->error('Quiz not found', 404);
        }

        $attempts = QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->get()
            ->map(fn (QuizAttempt $attempt) => $this->formatAttempt($attempt))
            ->values()
            ->all();

        return $this->success('Quiz attempts retrieved successfully', $attempts);
    }

    public function result(Request $request, $id)
    {
        $attempt = QuizAttempt::query()
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $attempt) {
            return $this->error('Quiz attempt not found', 404);
        }

        return $this->success('Quiz attempt retrieved successfully', $this->formatAttempt($attempt));
    }

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    */

    public function adminIndex(Request $request)
    {
        $query = Quiz::query()->withCount('questions');

        if (! $request->user()->isAdmin()) {
            $query->whereIn('course_id', \App\Models\Course::where('instructor_id', $request->user()->id)->pluck('id'));
        }

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->course_id);
        }

        if ($request->filled('lesson_id')) {
            $query->where('lesson_id', $request->lesson_id);
        }

        $quizzes = $query->orderBy('sort_order')->orderBy('id')->get();

        return $this->success(
            'Quizzes retrieved successfully',
            $quizzes->map(fn (Quiz $quiz) => $this->formatAdminQuiz($quiz))->values()->all()
        );
    }

    public function adminShow($id)
    {
        if ($d = $this->denyQuiz($id)) { return $d; }
        $quiz = Quiz::with(['questions', 'course:id,title,slug'])->find($id);

        if (! $quiz) {
            return $this->error('Quiz not found', 404);
        }

        $data = $this->formatAdminQuiz($quiz);
        $data['questions'] = $quiz->questions->map(fn (QuizQuestion $question) => $this->formatAdminQuestion($question))->values()->all();

        return $this->success('Quiz retrieved successfully', $data);
    }

    public function store(Request $request)
    {
        if ($d = $this->denyCourse($request->course_id)) { return $d; }
        $request->validate([
            'course_id' => 'required|exists:courses,id',
            'lesson_id' => 'nullable|exists:lessons,id|unique:quizzes,lesson_id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'pass_percentage' => 'nullable|integer|min:0|max:100',
            'time_limit_minutes' => 'nullable|integer|min:1',
            'max_attempts' => 'nullable|integer|min:1',
            'is_published' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $quiz = Quiz::create([
            'course_id' => $request->course_id,
            'lesson_id' => $request->lesson_id,
            'title' => $request->title,
            'description' => $request->description,
            'pass_percentage' => (int) $request->input('pass_percentage', 60),
            'time_limit_minutes' => $request->time_limit_minutes,
            'max_attempts' => $request->max_attempts,
            'is_published' => $request->has('is_published') ? $request->boolean('is_published') : true,
            'sort_order' => (int) $request->input('sort_order', 0),
        ]);

        return $this->success('Quiz created successfully', $this->formatAdminQuiz($quiz, true));
    }

    public function update(Request $request)
    {
        if ($d = $this->denyQuiz($request->id)) { return $d; }
        $request->validate([
            'id' => 'required|exists:quizzes,id',
            'lesson_id' => 'nullable|exists:lessons,id|unique:quizzes,lesson_id,'.$request->id,
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'pass_percentage' => 'nullable|integer|min:0|max:100',
            'time_limit_minutes' => 'nullable|integer|min:1',
            'max_attempts' => 'nullable|integer|min:1',
            'is_published' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $quiz = Quiz::findOrFail($request->id);

        $quiz->fill([
            'lesson_id' => $request->has('lesson_id') ? $request->lesson_id : $quiz->lesson_id,
            'title' => $request->title,
            'description' => $request->description,
            'pass_percentage' => $request->has('pass_percentage') ? (int) $request->pass_percentage : $quiz->pass_percentage,
            'time_limit_minutes' => $request->has('time_limit_minutes') ? $request->time_limit_minutes : $quiz->time_limit_minutes,
            'max_attempts' => $request->has('max_attempts') ? $request->max_attempts : $quiz->max_attempts,
            'is_published' => $request->has('is_published') ? $request->boolean('is_published') : $quiz->is_published,
            'sort_order' => $request->has('sort_order') ? (int) $request->sort_order : $quiz->sort_order,
        ])->save();

        return $this->success('Quiz updated successfully', $this->formatAdminQuiz($quiz->fresh()->loadCount('questions'), true));
    }

    public function destroy($id)
    {
        if ($d = $this->denyQuiz($id)) { return $d; }
        $quiz = Quiz::find($id);

        if (! $quiz) {
            return $this->error('Quiz not found', 404);
        }

        $quiz->delete();

        return $this->success('Quiz deleted successfully', null);
    }

    public function storeQuestion(Request $request)
    {
        if ($d = $this->denyQuiz($request->quiz_id)) { return $d; }
        $rules = [
            'quiz_id' => 'required|exists:quizzes,id',
            'type' => 'nullable|in:single_choice,multiple_choice,true_false',
            'question' => 'required|string',
            'options' => 'required|array|min:2',
            'options.*.id' => 'required',
            'options.*.text' => 'required|string',
            'correct_answers' => 'required|array|min:1',
            'marks' => 'nullable|integer|min:1',
            'sort_order' => 'nullable|integer',
        ];

        $request->validate($rules);

        $type = $request->input('type', 'single_choice');

        if ($type !== 'multiple_choice' && count($request->correct_answers) > 1) {
            return $this->error('Only multiple choice questions can have more than one correct answer');
        }

        $question = QuizQuestion::create([
            'quiz_id' => $request->quiz_id,
            'type' => $type,
            'question' => $request->question,
            'options' => $request->options,
            'correct_answers' => array_values($request->correct_answers),
            'marks' => (int) $request->input('marks', 1),
            'sort_order' => (int) $request->input('sort_order', 0),
        ]);

        return $this->success('Question created successfully', $this->formatAdminQuestion($question));
    }

    public function updateQuestion(Request $request)
    {
        if ($d = $this->denyQuestion($request->id)) { return $d; }
        $request->validate([
            'id' => 'required|exists:quiz_questions,id',
            'type' => 'nullable|in:single_choice,multiple_choice,true_false',
            'question' => 'required|string',
            'options' => 'required|array|min:2',
            'options.*.id' => 'required',
            'options.*.text' => 'required|string',
            'correct_answers' => 'required|array|min:1',
            'marks' => 'nullable|integer|min:1',
            'sort_order' => 'nullable|integer',
        ]);

        $question = QuizQuestion::findOrFail($request->id);
        $type = $request->input('type', $question->type);

        if ($type !== 'multiple_choice' && count($request->correct_answers) > 1) {
            return $this->error('Only multiple choice questions can have more than one correct answer');
        }

        $question->fill([
            'type' => $type,
            'question' => $request->question,
            'options' => $request->options,
            'correct_answers' => array_values($request->correct_answers),
            'marks' => $request->has('marks') ? (int) $request->marks : $question->marks,
            'sort_order' => $request->has('sort_order') ? (int) $request->sort_order : $question->sort_order,
        ])->save();

        return $this->success('Question updated successfully', $this->formatAdminQuestion($question->fresh()));
    }

    public function destroyQuestion($id)
    {
        if ($d = $this->denyQuestion($id)) { return $d; }
        $question = QuizQuestion::find($id);

        if (! $question) {
            return $this->error('Question not found', 404);
        }

        $question->delete();

        return $this->success('Question deleted successfully', null);
    }

    private function formatAttempt(QuizAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'quiz_id' => $attempt->quiz_id,
            'score' => $attempt->score,
            'total_marks' => $attempt->total_marks,
            'percentage' => (float) $attempt->percentage,
            'passed' => $attempt->passed,
            'answers' => $attempt->answers,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'completed_at' => $attempt->completed_at?->toIso8601String(),
        ];
    }

    private function formatAdminQuiz(Quiz $quiz, bool $includeQuestions = false): array
    {
        $data = [
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
            'questions_count' => $quiz->questions_count ?? $quiz->questions()->count(),
            'course' => $quiz->relationLoaded('course') && $quiz->course ? [
                'id' => $quiz->course->id,
                'title' => $quiz->course->title,
                'slug' => $quiz->course->slug,
            ] : null,
            'created_at' => $quiz->created_at?->toIso8601String(),
        ];

        if ($includeQuestions) {
            $data['questions'] = $quiz->questions()
                ->get()
                ->map(fn (QuizQuestion $question) => $this->formatAdminQuestion($question))
                ->values()
                ->all();
        }

        return $data;
    }

    private function formatAdminQuestion(QuizQuestion $question): array
    {
        return [
            'id' => $question->id,
            'quiz_id' => $question->quiz_id,
            'type' => $question->type,
            'question' => $question->question,
            'options' => $question->options,
            'correct_answers' => $question->correct_answers,
            'marks' => $question->marks,
            'sort_order' => $question->sort_order,
        ];
    }
}
