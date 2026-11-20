<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['type' => 'admin']);
        $this->student = User::factory()->create(['type' => 'customer']);
    }

    private function makeExam(array $exam = [], int $questions = 3): array
    {
        Sanctum::actingAs($this->admin);

        $created = $this->postJson('api/admin/exams/add', $exam + [
            'title' => 'Mock Test',
            'duration_minutes' => 30,
            'pass_percentage' => 50,
            'is_published' => false,
        ])->assertOk()->json('data');

        $ids = [];
        for ($i = 1; $i <= $questions; $i++) {
            $ids[$i] = $this->postJson('api/admin/exam-questions/add', [
                'exam_id' => $created['id'],
                'type' => 'single_choice',
                'question' => "Question $i",
                'options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B'], ['id' => 'c', 'text' => 'C']],
                'correct_answers' => ['b'],
                'marks' => 4,
            ])->assertOk()->json('data.id');
        }

        $this->postJson('api/admin/exams/update', $exam + [
            'id' => $created['id'], 'title' => 'Mock Test', 'duration_minutes' => 30, 'pass_percentage' => 50, 'is_published' => true,
        ])->assertOk();

        return [$created['id'], $ids];
    }

    private function take(int $examId, array $answers): array
    {
        Sanctum::actingAs($this->student);
        $attempt = $this->postJson("api/exams/$examId/start")->assertOk()->json('data');
        $this->assertSame('in_progress', $attempt['attempt']['status']);

        return $this->postJson('api/exam-attempts/'.$attempt['attempt']['id'].'/submit', ['answers' => $answers])
            ->assertOk()->json('data');
    }

    public function test_marks_automatically_with_fractional_negative_marking(): void
    {
        [$examId, $q] = $this->makeExam(['negative_marking' => true, 'negative_marking_type' => 'fraction', 'negative_marking_value' => 0.25]);

        // right, wrong, skipped -> 4 - (4 * 0.25) = 3 of 12
        $result = $this->take($examId, [$q[1] => ['b'], $q[2] => ['a']]);

        $a = $result['attempt'];
        $this->assertEquals(3, $a['score']);
        $this->assertEquals(12, $a['total_marks']);
        $this->assertEquals(25, $a['percentage']);
        $this->assertSame([1, 1, 1], [$a['correct_count'], $a['wrong_count'], $a['unanswered_count']]);
        $this->assertEquals(1, $a['negative_marks_total']);
        $this->assertFalse($a['passed']);
    }

    public function test_fixed_negative_marking_and_no_negative_marking(): void
    {
        [$fixed, $q] = $this->makeExam(['negative_marking' => true, 'negative_marking_type' => 'fixed', 'negative_marking_value' => 1.5]);
        $result = $this->take($fixed, [$q[1] => ['b'], $q[2] => ['b'], $q[3] => ['c']]);
        $this->assertEquals(6.5, $result['attempt']['score']); // 8 - 1.5

        $this->student = User::factory()->create(['type' => 'customer']);
        [$none, $q2] = $this->makeExam(['negative_marking' => false, 'negative_marking_value' => 5]);
        $result = $this->take($none, [$q2[1] => ['b'], $q2[2] => ['a'], $q2[3] => ['a']]);
        $this->assertEquals(4, $result['attempt']['score']); // wrong answers cost nothing
        $this->assertEquals(0, $result['attempt']['negative_marks_total']);
    }

    public function test_total_is_floored_at_zero_unless_negative_totals_are_allowed(): void
    {
        $rules = ['negative_marking' => true, 'negative_marking_type' => 'fixed', 'negative_marking_value' => 3];

        [$floored, $q] = $this->makeExam($rules);
        $this->assertEquals(0, $this->take($floored, [$q[1] => ['a'], $q[2] => ['a'], $q[3] => ['a']])['attempt']['score']);

        $this->student = User::factory()->create(['type' => 'customer']);
        [$open, $q2] = $this->makeExam($rules + ['allow_negative_total' => true]);
        $this->assertEquals(-9, $this->take($open, [$q2[1] => ['a'], $q2[2] => ['a'], $q2[3] => ['a']])['attempt']['score']);
    }

    public function test_per_question_penalty_override_and_multiple_choice_is_exact(): void
    {
        Sanctum::actingAs($this->admin);
        $exam = $this->postJson('api/admin/exams/add', [
            'title' => 'MC', 'duration_minutes' => 10, 'negative_marking' => true, 'negative_marking_type' => 'fraction', 'negative_marking_value' => 0.25,
        ])->json('data.id');

        $mc = $this->postJson('api/admin/exam-questions/add', [
            'exam_id' => $exam, 'type' => 'multiple_choice', 'question' => 'Pick two',
            'options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B'], ['id' => 'c', 'text' => 'C']],
            'correct_answers' => ['a', 'b'], 'marks' => 2, 'negative_marks' => 2,
        ])->assertOk()->json('data.id');
        $this->postJson('api/admin/exams/update', ['id' => $exam, 'title' => 'MC', 'duration_minutes' => 10, 'is_published' => true, 'negative_marking' => true, 'negative_marking_type' => 'fraction', 'negative_marking_value' => 0.25])->assertOk();

        // Only one of two correct options is NOT a correct answer -> penalised with the override (2), floored at 0.
        $partial = $this->take($exam, [$mc => ['a']]);
        $this->assertEquals(2, $partial['attempt']['negative_marks_total']);
        $this->assertSame(1, $partial['attempt']['wrong_count']);

        $this->student = User::factory()->create(['type' => 'customer']);
        $full = $this->take($exam, [$mc => ['b', 'a']]);
        $this->assertEquals(2, $full['attempt']['score']);
        $this->assertTrue($full['attempt']['passed']);
    }

    public function test_questions_never_leak_answers_and_review_respects_show_answers(): void
    {
        [$examId, $q] = $this->makeExam(['show_answers' => 'never']);

        Sanctum::actingAs($this->student);
        $start = $this->postJson("api/exams/$examId/start")->assertOk()->json('data');
        $this->assertStringNotContainsString('correct_answers', json_encode($start));
        $this->assertCount(3, $start['questions']);

        $result = $this->postJson('api/exam-attempts/'.$start['attempt']['id'].'/submit', ['answers' => [$q[1] => ['b']]])->json('data');
        $this->assertFalse($result['answers_visible']);
        $this->assertNull($result['review'][0]['correct_answers']);
        $this->assertSame('correct', $result['review'][0]['status']);

        Sanctum::actingAs($this->admin);
        $this->postJson('api/admin/exams/update', ['id' => $examId, 'title' => 'Mock Test', 'duration_minutes' => 30, 'is_published' => true, 'show_answers' => 'after_submit'])->assertOk();

        Sanctum::actingAs($this->student);
        $again = $this->getJson('api/exam-attempts/'.$start['attempt']['id'])->json('data');
        $this->assertTrue($again['answers_visible']);
        $this->assertSame(['b'], $again['review'][0]['correct_answers']);
    }

    public function test_attempt_resume_limits_and_privacy(): void
    {
        [$examId] = $this->makeExam(['max_attempts' => 1]);

        Sanctum::actingAs($this->student);
        $first = $this->postJson("api/exams/$examId/start")->json('data.attempt.id');
        $this->assertSame($first, $this->postJson("api/exams/$examId/start")->json('data.attempt.id'), 'Starting twice resumes');
        $this->assertSame(1, ExamAttempt::count());

        $this->postJson("api/exam-attempts/$first/answers", ['answers' => ['999' => ['a']]])->assertOk()->assertJsonPath('data.saved_count', 0);

        $this->postJson("api/exam-attempts/$first/submit")->assertOk();
        $this->postJson("api/exam-attempts/$first/submit")->assertOk(); // idempotent
        $this->postJson("api/exams/$examId/start")->assertJsonPath('status', false); // max attempts

        Sanctum::actingAs(User::factory()->create(['type' => 'customer']));
        $this->getJson("api/exam-attempts/$first")->assertStatus(404);
    }

    public function test_time_limit_is_enforced_on_the_server(): void
    {
        [$examId, $q] = $this->makeExam();

        Sanctum::actingAs($this->student);
        $attemptId = $this->postJson("api/exams/$examId/start")->json('data.attempt.id');
        $this->postJson("api/exam-attempts/$attemptId/answers", ['answers' => [$q[1] => ['b']]])->assertOk();

        // Past the deadline + grace: new answers are refused and the saved ones are marked.
        $this->travel(31)->minutes();
        $result = $this->postJson("api/exam-attempts/$attemptId/submit", ['answers' => [$q[2] => ['b'], $q[3] => ['b']]])->assertOk()->json('data');

        $this->assertSame('expired', $result['attempt']['status']);
        $this->assertEquals(4, $result['attempt']['score']);
        $this->assertSame(1, $result['attempt']['correct_count']);
    }

    public function test_scheduled_command_marks_abandoned_attempts(): void
    {
        [$examId, $q] = $this->makeExam();

        Sanctum::actingAs($this->student);
        $attemptId = $this->postJson("api/exams/$examId/start")->json('data.attempt.id');
        $this->postJson("api/exam-attempts/$attemptId/answers", ['answers' => [$q[1] => ['b'], $q[2] => ['b']]])->assertOk();

        $this->travel(31)->minutes();
        $this->artisan('exams:finalize-expired')->assertSuccessful();

        $attempt = ExamAttempt::find($attemptId);
        $this->assertSame('expired', $attempt->status);
        $this->assertEquals(8, $attempt->score);
        $this->assertNull($attempt->active_key);
    }

    public function test_window_course_access_and_admin_rules(): void
    {
        // Not yet open
        [$later] = $this->makeExam(['starts_at' => now()->addDay()->toIso8601String()]);
        Sanctum::actingAs($this->student);
        $this->postJson("api/exams/$later/start")->assertJsonPath('status', false);

        // Course exam requires enrollment
        $course = Course::create(['title' => 'C', 'slug' => 'c', 'price' => 10, 'status' => 'published', 'published_at' => now()->subDay()]);
        [$courseExam] = $this->makeExam(['course_id' => $course->id]);
        Sanctum::actingAs($this->student);
        $this->postJson("api/exams/$courseExam/start")->assertJsonPath('status', false);
        $this->getJson('api/exams')->assertJsonMissing(['id' => $courseExam]);

        Enrollment::create(['user_id' => $this->student->id, 'course_id' => $course->id, 'source' => 'manual', 'status' => 'active', 'enrolled_at' => now(), 'expires_at' => now()->addDays(3)]);
        $this->postJson("api/exams/$courseExam/start")->assertJsonPath('status', true);

        // Unpublished exams are invisible
        Sanctum::actingAs($this->admin);
        $draft = $this->postJson('api/admin/exams/add', ['title' => 'Draft', 'duration_minutes' => 5])->json('data.id');
        Sanctum::actingAs($this->student);
        $this->postJson("api/exams/$draft/start")->assertStatus(404);

        // Validation: a fractional penalty above 1 is rejected; publishing needs questions
        Sanctum::actingAs($this->admin);
        $this->postJson('api/admin/exams/add', ['title' => 'x', 'duration_minutes' => 5, 'negative_marking' => true, 'negative_marking_type' => 'fraction', 'negative_marking_value' => 2])->assertStatus(422);
        $this->postJson('api/admin/exams/update', ['id' => $draft, 'title' => 'Draft', 'duration_minutes' => 5, 'is_published' => true])->assertStatus(422);

        // Students cannot use the admin API
        Sanctum::actingAs($this->student);
        $this->getJson('api/admin/exams')->assertStatus(403);
    }

    public function test_questions_are_locked_after_attempts_and_instructors_stay_in_their_courses(): void
    {
        [$examId, $q] = $this->makeExam();
        $this->take($examId, [$q[1] => ['b']]);

        Sanctum::actingAs($this->admin);
        $this->postJson('api/admin/exam-questions/update', [
            'id' => $q[1], 'type' => 'single_choice', 'question' => 'changed', 'options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']], 'correct_answers' => ['a'], 'marks' => 1,
        ])->assertOk()->assertJsonPath('status', false);
        $this->deleteJson("api/admin/exams/delete/$examId")->assertOk()->assertJsonPath('status', false);

        $results = $this->getJson("api/admin/exams/$examId/attempts")->assertOk()->json('data');
        $this->assertSame(1, $results['stats']['attempts']);
        $this->assertEquals(4, $results['attempts']['data'][0]['score']);

        $instructor = User::factory()->create(['type' => 'instructor']);
        Sanctum::actingAs($instructor);
        $this->getJson("api/admin/exams/$examId")->assertStatus(403); // standalone = admin only
        $this->postJson('api/admin/exams/add', ['title' => 'No course', 'duration_minutes' => 5])->assertStatus(422);
    }
}
