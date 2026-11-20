<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use Illuminate\Http\Request;

/**
 * Exam management for admins (all exams) and instructors (exams of their own courses).
 */
class ExamAdminController extends Controller
{
    /** Standalone exams (no course) are admin-only. */
    private function allowed(Exam $exam): bool
    {
        $user = request()->user();

        if ($user->isAdmin()) {
            return true;
        }

        return $exam->course_id && $this->canManageCourse($exam->course);
    }

    private function rules(): array
    {
        return [
            'course_id' => 'nullable|exists:courses,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',
            'duration_minutes' => 'required|integer|min:1|max:1440',
            'pass_percentage' => 'nullable|integer|min:0|max:100',
            'max_attempts' => 'nullable|integer|min:1',
            'negative_marking' => 'nullable|boolean',
            'negative_marking_type' => 'nullable|in:fraction,fixed',
            'negative_marking_value' => 'nullable|numeric|min:0|max:1000',
            'allow_negative_total' => 'nullable|boolean',
            'shuffle_questions' => 'nullable|boolean',
            'shuffle_options' => 'nullable|boolean',
            'show_answers' => 'nullable|in:after_submit,after_exam_end,never',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'is_published' => 'nullable|boolean',
        ];
    }

    private function fill(Exam $exam, Request $request): Exam|\Illuminate\Http\JsonResponse
    {
        $request->validate($this->rules());

        $type = $request->input('negative_marking_type', $exam->negative_marking_type ?? 'fraction');
        $value = (float) $request->input('negative_marking_value', $exam->negative_marking_value ?? 0);

        if ($request->boolean('negative_marking') && $type === 'fraction' && $value > 1) {
            return response()->json(['status' => false, 'message' => 'A fractional penalty must be between 0 and 1 (e.g. 0.25 = a quarter of the question marks)', 'errors' => ['negative_marking_value' => ['Use a value between 0 and 1']]], 422);
        }

        if ($request->boolean('negative_marking') && $value <= 0 && ! $request->has('negative_marking_value')) {
            return response()->json(['status' => false, 'message' => 'Enter the negative marking value', 'errors' => ['negative_marking_value' => ['Required when negative marking is on']]], 422);
        }

        $courseId = $request->input('course_id');

        if ($courseId) {
            if (! $this->canManageCourse(Course::find($courseId))) {
                return $this->forbidden();
            }
        } elseif (! $request->user()->isAdmin()) {
            return response()->json(['status' => false, 'message' => 'Instructors must attach the exam to one of their courses'], 422);
        }

        $exam->fill([
            'course_id' => $courseId,
            'title' => $request->title,
            'description' => $request->description,
            'instructions' => $request->instructions,
            'duration_minutes' => (int) $request->duration_minutes,
            'pass_percentage' => (int) $request->input('pass_percentage', $exam->pass_percentage ?? 50),
            'max_attempts' => $request->input('max_attempts'),
            'negative_marking' => $request->boolean('negative_marking'),
            'negative_marking_type' => $type,
            'negative_marking_value' => $value,
            'allow_negative_total' => $request->boolean('allow_negative_total'),
            'shuffle_questions' => $request->boolean('shuffle_questions'),
            'shuffle_options' => $request->boolean('shuffle_options'),
            'show_answers' => $request->input('show_answers', 'after_submit'),
            'starts_at' => $request->input('starts_at'),
            'ends_at' => $request->input('ends_at'),
            'is_published' => $request->boolean('is_published'),
        ]);

        if ($exam->is_published && ! $exam->exists) {
            // A new exam has no questions yet; publishing is allowed but flagged in the UI.
        } elseif ($exam->is_published && $exam->questions()->doesntExist()) {
            return response()->json(['status' => false, 'message' => 'Add at least one question before publishing', 'errors' => ['is_published' => ['Add questions first']]], 422);
        }

        $exam->save();

        return $exam;
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $exams = Exam::query()
            ->with('course:id,title,slug')
            ->withCount(['questions', 'attempts'])
            ->withSum('questions', 'marks')
            ->when(! $user->isAdmin(), fn ($q) => $q->whereIn('course_id', Course::where('instructor_id', $user->id)->pluck('id')))
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->course_id))
            ->latest('id')
            ->get();

        return $this->success('Exams retrieved successfully', $exams->map(fn (Exam $e) => $this->format($e))->values());
    }

    public function show($id)
    {
        $exam = Exam::with(['course:id,title,slug', 'questions'])->withCount('attempts')->withSum('questions', 'marks')->find($id);

        if (! $exam) {
            return $this->notFound('Exam not found');
        }

        if (! $this->allowed($exam)) {
            return $this->forbidden();
        }

        return $this->success('Exam retrieved successfully', $this->format($exam) + [
            'questions' => $exam->questions->map(fn (ExamQuestion $q) => $this->formatQuestion($q))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $result = $this->fill(new Exam, $request);

        return $result instanceof Exam ? $this->success('Exam created successfully', $this->format($result)) : $result;
    }

    public function update(Request $request)
    {
        $exam = Exam::find($request->id);

        if (! $exam) {
            return $this->notFound('Exam not found');
        }

        if (! $this->allowed($exam)) {
            return $this->forbidden();
        }

        $result = $this->fill($exam, $request);

        return $result instanceof Exam
            ? $this->success('Exam updated successfully', $this->format($result->loadCount(['questions', 'attempts'])->loadSum('questions', 'marks')))
            : $result;
    }

    public function destroy($id)
    {
        $exam = Exam::withCount('attempts')->find($id);

        if (! $exam) {
            return $this->notFound('Exam not found');
        }

        if (! $this->allowed($exam)) {
            return $this->forbidden();
        }

        if ($exam->attempts_count > 0) {
            return $this->error('Students have already attempted this exam. Unpublish it instead of deleting.');
        }

        $exam->delete();

        return $this->success('Exam deleted successfully', null);
    }

    /*
    |--------------------------------------------------------------------------
    | Questions
    |--------------------------------------------------------------------------
    */

    private function questionRules(): array
    {
        return [
            'type' => 'required|in:single_choice,multiple_choice,true_false',
            'question' => 'required|string',
            'options' => 'required|array|min:2|max:10',
            'options.*.id' => 'required',
            'options.*.text' => 'required|string',
            'correct_answers' => 'required|array|min:1',
            'marks' => 'required|numeric|min:0.25|max:1000',
            'negative_marks' => 'nullable|numeric|min:0|max:1000',
            'explanation' => 'nullable|string',
            'sort_order' => 'nullable|integer',
        ];
    }

    /** Questions are frozen once students have attempted the exam (results must stay reproducible). */
    private function lockedByAttempts(Exam $exam): bool
    {
        return $exam->attempts()->exists();
    }

    private function validateQuestion(Request $request)
    {
        $ids = collect($request->input('options', []))->pluck('id')->map(fn ($v) => (string) $v);

        if ($ids->count() !== $ids->unique()->count()) {
            return $this->error('Option ids must be unique');
        }

        $correct = collect($request->input('correct_answers', []))->map(fn ($v) => (string) $v);

        if ($correct->diff($ids)->isNotEmpty()) {
            return $this->error('Correct answers must be among the options');
        }

        if ($request->input('type') !== 'multiple_choice' && $correct->count() > 1) {
            return $this->error('Only multiple choice questions can have more than one correct answer');
        }

        return null;
    }

    public function storeQuestion(Request $request)
    {
        $request->validate(['exam_id' => 'required|exists:exams,id'] + $this->questionRules());
        $exam = Exam::findOrFail($request->exam_id);

        if (! $this->allowed($exam)) {
            return $this->forbidden();
        }

        if ($this->lockedByAttempts($exam)) {
            return $this->error('Students have already attempted this exam, so its questions are locked.');
        }

        if ($bad = $this->validateQuestion($request)) {
            return $bad;
        }

        $question = ExamQuestion::create([
            'exam_id' => $exam->id,
            'type' => $request->type,
            'question' => $request->question,
            'options' => array_values($request->options),
            'correct_answers' => array_map('strval', array_values($request->correct_answers)),
            'marks' => $request->marks,
            'negative_marks' => $request->negative_marks,
            'explanation' => $request->explanation,
            'sort_order' => (int) $request->input('sort_order', $exam->questions()->count()),
        ]);

        return $this->success('Question created successfully', $this->formatQuestion($question));
    }

    public function updateQuestion(Request $request)
    {
        $request->validate(['id' => 'required|exists:exam_questions,id'] + $this->questionRules());
        $question = ExamQuestion::with('exam')->findOrFail($request->id);

        if (! $this->allowed($question->exam)) {
            return $this->forbidden();
        }

        if ($this->lockedByAttempts($question->exam)) {
            return $this->error('Students have already attempted this exam, so its questions are locked.');
        }

        if ($bad = $this->validateQuestion($request)) {
            return $bad;
        }

        $question->fill([
            'type' => $request->type,
            'question' => $request->question,
            'options' => array_values($request->options),
            'correct_answers' => array_map('strval', array_values($request->correct_answers)),
            'marks' => $request->marks,
            'negative_marks' => $request->negative_marks,
            'explanation' => $request->explanation,
            'sort_order' => $request->has('sort_order') ? (int) $request->sort_order : $question->sort_order,
        ])->save();

        return $this->success('Question updated successfully', $this->formatQuestion($question));
    }

    public function destroyQuestion($id)
    {
        $question = ExamQuestion::with('exam')->find($id);

        if (! $question) {
            return $this->notFound('Question not found');
        }

        if (! $this->allowed($question->exam)) {
            return $this->forbidden();
        }

        if ($this->lockedByAttempts($question->exam)) {
            return $this->error('Students have already attempted this exam, so its questions are locked.');
        }

        $question->delete();

        return $this->success('Question deleted successfully', null);
    }

    /*
    |--------------------------------------------------------------------------
    | Results
    |--------------------------------------------------------------------------
    */

    public function attempts(Request $request, $id)
    {
        $exam = Exam::find($id);

        if (! $exam) {
            return $this->notFound('Exam not found');
        }

        if (! $this->allowed($exam)) {
            return $this->forbidden();
        }

        $finished = ExamAttempt::where('exam_id', $exam->id)->where('status', '!=', 'in_progress');

        $stats = [
            'attempts' => (clone $finished)->count(),
            'students' => (clone $finished)->distinct('user_id')->count('user_id'),
            'average_percentage' => round((float) (clone $finished)->avg('percentage'), 2),
            'highest_percentage' => (float) (clone $finished)->max('percentage'),
            'pass_rate' => ($n = (clone $finished)->count()) > 0 ? round((clone $finished)->where('passed', true)->count() / $n * 100, 2) : 0,
        ];

        $attempts = ExamAttempt::with('user:id,name,email')
            ->where('exam_id', $exam->id)
            ->where('status', '!=', 'in_progress')
            ->orderByDesc('percentage')->orderBy('submitted_at')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        $attempts->setCollection($attempts->getCollection()->map(fn (ExamAttempt $a) => [
            'id' => $a->id,
            'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name, 'email' => $a->user->email] : null,
            'status' => $a->status,
            'score' => (float) $a->score,
            'total_marks' => (float) $a->total_marks,
            'percentage' => (float) $a->percentage,
            'correct_count' => $a->correct_count,
            'wrong_count' => $a->wrong_count,
            'unanswered_count' => $a->unanswered_count,
            'negative_marks_total' => (float) $a->negative_marks_total,
            'passed' => $a->passed,
            'submitted_at' => $a->submitted_at?->toIso8601String(),
        ]));

        return $this->success('Results retrieved successfully', ['stats' => $stats, 'attempts' => $attempts]);
    }

    /*
    |--------------------------------------------------------------------------
    | Formatting
    |--------------------------------------------------------------------------
    */

    private function format(Exam $exam): array
    {
        return [
            'id' => $exam->id,
            'course_id' => $exam->course_id,
            'course' => $exam->relationLoaded('course') && $exam->course
                ? ['id' => $exam->course->id, 'title' => $exam->course->title, 'slug' => $exam->course->slug]
                : null,
            'title' => $exam->title,
            'description' => $exam->description,
            'instructions' => $exam->instructions,
            'duration_minutes' => $exam->duration_minutes,
            'pass_percentage' => $exam->pass_percentage,
            'max_attempts' => $exam->max_attempts,
            'negative_marking' => $exam->negative_marking,
            'negative_marking_type' => $exam->negative_marking_type,
            'negative_marking_value' => $exam->negative_marking_value,
            'allow_negative_total' => $exam->allow_negative_total,
            'shuffle_questions' => $exam->shuffle_questions,
            'shuffle_options' => $exam->shuffle_options,
            'show_answers' => $exam->show_answers,
            'starts_at' => $exam->starts_at?->toIso8601String(),
            'ends_at' => $exam->ends_at?->toIso8601String(),
            'is_published' => $exam->is_published,
            'state' => $exam->windowState(),
            'questions_count' => (int) ($exam->questions_count ?? 0),
            'attempts_count' => (int) ($exam->attempts_count ?? 0),
            'total_marks' => (float) ($exam->questions_sum_marks ?? 0),
            'created_at' => $exam->created_at?->toIso8601String(),
        ];
    }

    private function formatQuestion(ExamQuestion $question): array
    {
        return [
            'id' => $question->id,
            'exam_id' => $question->exam_id,
            'type' => $question->type,
            'question' => $question->question,
            'options' => $question->options,
            'correct_answers' => $question->correct_answers,
            'marks' => $question->marks,
            'negative_marks' => $question->negative_marks,
            'explanation' => $question->explanation,
            'sort_order' => $question->sort_order,
        ];
    }
}
