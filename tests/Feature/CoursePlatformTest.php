<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoursePlatformTest extends TestCase
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

    public function test_full_course_lifecycle(): void
    {
        // --- Admin builds the course -------------------------------------
        Sanctum::actingAs($this->admin);

        $category = $this->postJson('api/admin/course-categories/add', [
            'name' => 'IELTS',
            'description' => 'IELTS preparation',
        ])->assertOk()->json('data');

        $course = $this->postJson('api/admin/courses/add', [
            'course_category_id' => $category['id'],
            'title' => 'IELTS Academic Booster',
            'subtitle' => 'Band 7 in 60 days',
            'price' => 5000,
            'offer_price' => 3500,
            'access_days' => 30,
            'status' => 'published',
        ])->assertOk()->json('data');

        $section = $this->postJson('api/admin/course-sections/add', [
            'course_id' => $course['id'],
            'title' => 'Listening Mastery',
        ])->assertOk()->json('data');

        $lesson = $this->postJson('api/admin/lessons/add', [
            'course_id' => $course['id'],
            'course_section_id' => $section['id'],
            'title' => 'Listening Test Overview',
            'type' => 'video',
            'video_source' => 'external',
            'video_url' => 'https://example.com/video.mp4',
            'duration_seconds' => 300,
            'is_preview' => false,
        ])->assertOk()->json('data');

        $quizLesson = $this->postJson('api/admin/lessons/add', [
            'course_id' => $course['id'],
            'course_section_id' => $section['id'],
            'title' => 'Listening Quiz',
            'type' => 'quiz',
        ])->assertOk()->json('data');

        $quiz = $this->postJson('api/admin/quizzes/add', [
            'course_id' => $course['id'],
            'lesson_id' => $quizLesson['id'],
            'title' => 'Listening Basics',
            'pass_percentage' => 50,
        ])->assertOk()->json('data');

        $question = $this->postJson('api/admin/quiz-questions/add', [
            'quiz_id' => $quiz['id'],
            'type' => 'single_choice',
            'question' => 'How many sections are in the IELTS listening test?',
            'options' => [
                ['id' => 'a', 'text' => '2'],
                ['id' => 'b', 'text' => '4'],
            ],
            'correct_answers' => ['b'],
            'marks' => 2,
        ])->assertOk()->json('data');

        $this->assertSame(2, Course::find($course['id'])->total_lessons);

        // --- Public catalog ----------------------------------------------
        $this->getJson('api/courses')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('api/courses/ielts-academic-booster')
            ->assertOk()
            ->assertJsonPath('data.access_type', 'time_limited')
            ->assertJsonPath('data.sections.0.lessons.0.is_locked', true)
            ->assertJsonPath('data.sections.0.lessons.0.video', null);

        // --- Student buys the course -------------------------------------
        Sanctum::actingAs($this->student);

        $this->postJson('api/cart/courses/add', ['course_id' => $course['id']])
            ->assertOk()
            ->assertJsonCount(1, 'data.added');

        $order = $this->postJson('api/orders/add', [
            'customer' => [
                'name' => 'Student One',
                'email' => $this->student->email,
                'phone' => '01700000000',
            ],
            'payment_method' => 'cash_on_delivery',
        ])->assertOk()->json('data');

        $this->assertSame(1, count($order['course_items']));
        $this->assertEquals(3500, $order['pricing']['total']);

        $enrollment = Enrollment::where('user_id', $this->student->id)->first();
        $this->assertNotNull($enrollment);
        $this->assertTrue($enrollment->isActive());
        $this->assertTrue($enrollment->expires_at->between(now()->addDays(29), now()->addDays(31)));

        $this->getJson('api/my/courses')->assertOk()->assertJsonCount(1, 'data');

        // Preview/learning payload unlocks content for enrolled student.
        $learning = $this->getJson('api/my/courses/ielts-academic-booster/learn')
            ->assertOk()
            ->assertJsonPath('data.access.has_access', true)
            ->json('data');

        $this->assertSame('https://example.com/video.mp4', $learning['sections'][0]['lessons'][0]['video']['url']);

        // --- Progress -----------------------------------------------------
        $this->postJson('api/lessons/'.$lesson['id'].'/complete')
            ->assertOk()
            ->assertJsonPath('data.completed_lessons', 1);

        // --- Review -------------------------------------------------------
        $this->postJson('api/courses/ielts-academic-booster/reviews', [
            'rating' => 5,
            'title' => 'Excellent',
            'comment' => 'Very practical lessons.',
        ])->assertOk();

        $this->getJson('api/courses/ielts-academic-booster')
            ->assertOk()
            ->assertJsonPath('data.rating.average', 5);

        // --- Quiz ---------------------------------------------------------
        $this->postJson('api/quizzes/'.$quiz['id'].'/submit', [
            'answers' => [$question['id'] => ['b']],
        ])
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.attempt.passed', true);

        $this->getJson('api/my/courses/ielts-academic-booster/learn')
            ->assertOk()
            ->assertJsonPath('data.progress.percentage', 100)
            ->assertJsonPath('data.progress.is_complete', true);

        // --- Certificate --------------------------------------------------
        $certificate = $this->postJson('api/courses/ielts-academic-booster/certificate')
            ->assertOk()
            ->json('data');

        $this->assertTrue($certificate['is_valid']);

        $this->get('api/certificates/'.$certificate['certificate_number'].'/download')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->getJson('api/certificates/verify/'.$certificate['certificate_number'])
            ->assertOk()
            ->assertJsonPath('data.valid', true);
    }

    public function test_expired_enrollment_locks_lessons(): void
    {
        $course = Course::create([
            'title' => 'Expired Course',
            'slug' => 'expired-course',
            'price' => 1000,
            'access_days' => 10,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $lesson = $course->lessons()->create([
            'title' => 'Locked Lesson',
            'type' => 'text',
            'content' => 'Secret',
            'is_published' => true,
        ]);

        Enrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $course->id,
            'source' => 'order',
            'status' => 'active',
            'enrolled_at' => now()->subDays(20),
            'expires_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($this->student);

        $this->getJson('api/lessons/'.$lesson->id)
            ->assertOk()
            ->assertJsonPath('status', false);

        $this->getJson('api/my/courses/expired-course')
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_cancelled_order_revokes_course_access(): void
    {
        $course = Course::create([
            'title' => 'Refundable Course',
            'slug' => 'refundable-course',
            'price' => 2000,
            'access_days' => 90,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($this->student);

        $this->postJson('api/cart/courses/add', ['course_id' => $course->id])->assertOk();

        $order = $this->postJson('api/orders/add', [
            'customer' => ['name' => 'Student', 'email' => $this->student->email],
            'payment_method' => 'cash_on_delivery',
        ])->assertOk()->json('data');

        $this->assertTrue(Enrollment::where('course_id', $course->id)->first()->isActive());

        Sanctum::actingAs($this->admin);

        $this->postJson('api/admin/orders/update', [
            'id' => $order['id'],
            'status' => 'cancelled',
        ])->assertOk();

        $this->assertSame('revoked', Enrollment::where('course_id', $course->id)->first()->status);
    }

    public function test_enrollment_can_wait_for_payment_confirmation(): void
    {
        config(['courses.enroll_on_order_placement' => false]);

        $course = Course::create([
            'title' => 'Paid Later Course',
            'slug' => 'paid-later-course',
            'price' => 1200,
            'access_days' => 60,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($this->student);

        $this->postJson('api/cart/courses/add', ['course_id' => $course->id])->assertOk();

        $order = $this->postJson('api/orders/add', [
            'customer' => ['name' => 'Student', 'email' => $this->student->email],
            'payment_method' => 'bank_transfer',
        ])->assertOk()->json('data');

        $this->assertNull(Enrollment::where('course_id', $course->id)->first());

        Sanctum::actingAs($this->admin);

        $this->postJson('api/admin/orders/update', [
            'id' => $order['id'],
            'payment_status' => 'paid',
        ])->assertOk();

        $this->assertTrue(Enrollment::where('course_id', $course->id)->first()->isActive());
    }

    public function test_uploaded_lesson_video_is_streamed_via_signed_url(): void
    {
        Storage::fake('local');

        $course = Course::create([
            'title' => 'Uploaded Video Course',
            'slug' => 'uploaded-video-course',
            'price' => 1500,
            'access_days' => 0,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($this->admin);

        $lesson = $this->post('api/admin/lessons/add', [
            'course_id' => $course->id,
            'title' => 'Uploaded Lesson',
            'type' => 'video',
            'video_source' => 'upload',
            'video' => UploadedFile::fake()->create('lesson.mp4', 512, 'video/mp4'),
        ], ['Accept' => 'application/json'])->assertOk()->json('data');

        $this->assertSame('upload', $lesson['video_source']);

        Sanctum::actingAs($this->student);

        Enrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $course->id,
            'source' => 'manual',
            'status' => 'active',
            'enrolled_at' => now(),
            'expires_at' => null,
        ]);

        $streamUrl = $this->getJson('api/lessons/'.$lesson['id'])
            ->assertOk()
            ->json('data.video.stream_url');

        $this->assertNotNull($streamUrl);

        $this->get($streamUrl)->assertOk();
    }
}
