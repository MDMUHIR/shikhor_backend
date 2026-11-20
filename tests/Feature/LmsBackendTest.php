<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LmsBackendTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['type' => 'admin']);
        $this->student = User::factory()->create(['type' => 'customer']);
        config([
            'services.stripe.secret' => 'sk_test_x',
            'services.stripe.webhook_secret' => 'whsec_test',
        ]);
    }

    private function course(array $attrs = []): Course
    {
        return Course::create($attrs + [
            'title' => 'Course '.uniqid(),
            'slug' => 'course-'.uniqid(),
            'price' => 1000,
            'access_days' => 30,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    private function order(User $user, Course $course, array $extra = [], array $headers = []): array
    {
        Sanctum::actingAs($user);
        $this->postJson('api/cart/courses/add', ['course_id' => $course->id])->assertOk();

        return $this->postJson('api/orders/add', $extra + [
            'customer' => ['name' => 'S', 'email' => $user->email],
            'payment_method' => 'stripe',
        ], $headers)->assertOk()->json('data');
    }

    private function signed(array $event, ?string $secret = 'whsec_test', ?int $time = null): array
    {
        $payload = json_encode($event);
        $t = $time ?? time();
        $sig = hash_hmac('sha256', $t.'.'.$payload, $secret);

        return [$payload, ['Stripe-Signature' => "t=$t,v1=$sig", 'Content-Type' => 'application/json']];
    }

    private function postWebhook(array $event, ?string $secret = 'whsec_test')
    {
        [$payload, $headers] = $this->signed($event, $secret);

        return $this->call('POST', 'api/webhooks/payments/stripe', [], [], [], $this->transformHeadersToServerVars($headers), $payload);
    }

    private function sessionEvent(string $id, string $sessionId, int $amount): array
    {
        return [
            'id' => $id,
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $sessionId,
                'payment_status' => 'paid',
                'payment_intent' => 'pi_123',
                'amount_total' => $amount,
                'currency' => 'bdt',
            ]],
        ];
    }

    public function test_free_course_enrolls_immediately(): void
    {
        $course = $this->course(['price' => 0]);

        $order = $this->order($this->student, $course);

        $this->assertSame('paid', $order['payment_status']);
        $this->assertTrue(Enrollment::where('course_id', $course->id)->first()->isActive());
    }

    public function test_coupon_is_priced_on_server_and_client_discount_is_ignored(): void
    {
        $course = $this->course(['price' => 1000]);
        Coupon::create(['code' => 'SAVE10', 'type' => 'percentage', 'discount' => 10, 'usage_limit' => 1, 'applies_to' => 'courses']);

        $order = $this->order($this->student, $course, [
            'pricing' => ['coupon_code' => 'save10', 'discount' => 999, 'discount_type' => 'fixed'],
        ]);

        $this->assertEquals(900, $order['pricing']['total']);
        $this->assertSame(1, Coupon::first()->times_used);

        // Usage limit reached for the next buyer.
        $other = User::factory()->create(['type' => 'customer']);
        Sanctum::actingAs($other);
        $this->postJson('api/cart/courses/add', ['course_id' => $course->id])->assertOk();
        $this->postJson('api/orders/add', ['pricing' => ['coupon_code' => 'SAVE10']])
            ->assertOk()->assertJsonPath('status', false);
    }

    public function test_idempotency_key_prevents_duplicate_orders(): void
    {
        $course = $this->course();
        $first = $this->order($this->student, $course, [], ['Idempotency-Key' => 'abc-1']);
        $second = $this->postJson('api/orders/add', [], ['Idempotency-Key' => 'abc-1'])->assertOk()->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, Order::count());
    }

    public function test_webhook_verifies_signature_amount_and_is_idempotent(): void
    {
        $course = $this->course(['price' => 1000]);
        $order = $this->order($this->student, $course);

        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://pay.test/cs_1'])]);

        $this->postJson('api/orders/'.$order['id'].'/pay', ['provider' => 'stripe'])
            ->assertOk()->assertJsonPath('data.checkout_url', 'https://pay.test/cs_1');

        // Repeated clicks reuse the same payment.
        $this->postJson('api/orders/'.$order['id'].'/pay', ['provider' => 'stripe'])->assertOk();
        $this->assertSame(1, Payment::count());

        // Forged signature
        $this->postWebhook($this->sessionEvent('evt_1', 'cs_1', 100000), 'wrong')->assertStatus(400);
        $this->assertNull(Enrollment::first());

        // Wrong amount never grants access
        $this->postWebhook($this->sessionEvent('evt_bad', 'cs_1', 100))->assertOk();
        $this->assertNull(Enrollment::first());
        $this->assertSame('failed', Payment::first()->status);

        // Fresh payment, correct amount
        Payment::query()->update(['status' => 'processing', 'failure_reason' => null]);
        $this->postWebhook($this->sessionEvent('evt_2', 'cs_1', 100000))->assertOk()->assertJsonPath('result', 'processed');

        $this->assertSame('paid', Order::first()->payment_status);
        $this->assertSame(1, Enrollment::count());

        // Replay is a no-op
        $this->postWebhook($this->sessionEvent('evt_2', 'cs_1', 100000))->assertOk()->assertJsonPath('result', 'duplicate');
        $this->postWebhook($this->sessionEvent('evt_3', 'cs_1', 100000))->assertOk();
        $this->assertSame(1, Enrollment::count());

        // Student was notified
        $this->assertGreaterThan(0, $this->student->notifications()->count());
    }

    public function test_refund_revokes_access_and_releases_coupon(): void
    {
        $course = $this->course(['price' => 1000]);
        Coupon::create(['code' => 'HALF', 'type' => 'fixed', 'discount' => 500, 'applies_to' => 'courses']);
        $order = $this->order($this->student, $course, ['pricing' => ['coupon_code' => 'HALF']]);
        $this->assertEquals(500, $order['pricing']['total']);

        Sanctum::actingAs($this->admin);
        $this->postJson('api/admin/orders/'.$order['id'].'/confirm-payment')->assertOk();
        $payment = Payment::first();
        $this->assertSame(1, Coupon::first()->times_used);

        $this->postJson('api/admin/payments/'.$payment->id.'/refund', ['amount' => 100])->assertOk();
        $this->assertTrue(Enrollment::first()->isActive(), 'Partial refund keeps access');

        $this->postJson('api/admin/payments/'.$payment->id.'/refund', ['amount' => 9999])
            ->assertOk()->assertJsonPath('status', false);

        $this->postJson('api/admin/payments/'.$payment->id.'/refund', [])->assertOk();
        $this->assertSame('cancelled', Enrollment::first()->status);
        $this->assertSame('refunded', Order::first()->payment_status);
        $this->assertSame(0, Coupon::first()->times_used);
        $this->assertEquals(500, (float) $payment->fresh()->refunded_amount);
    }

    public function test_youtube_lessons_are_validated_and_embedded(): void
    {
        Sanctum::actingAs($this->admin);
        $course = $this->course();

        $this->postJson('api/admin/lessons/add', [
            'course_id' => $course->id, 'title' => 'Bad', 'type' => 'video',
            'video_source' => 'youtube', 'video_url' => 'https://evil.example/watch?v=dQw4w9WgXcQ',
        ])->assertStatus(422);

        $this->postJson('api/admin/lessons/add', [
            'course_id' => $course->id, 'title' => 'Plain http', 'type' => 'video',
            'video_url' => 'http://example.com/a.mp4',
        ])->assertStatus(422);

        $lesson = $this->postJson('api/admin/lessons/add', [
            'course_id' => $course->id, 'title' => 'Good', 'type' => 'video', 'is_preview' => true,
            'video_source' => 'youtube', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertOk()->json('data');

        $this->assertSame('youtube', $lesson['video_source']);

        Sanctum::actingAs($this->student);
        $this->getJson('api/lessons/'.$lesson['id'])->assertOk()
            ->assertJsonPath('data.video.source', 'youtube')
            ->assertJsonPath('data.video.video_id', 'dQw4w9WgXcQ');
    }

    public function test_review_moderation_and_rating_aggregation(): void
    {
        config(['courses.reviews.auto_approve' => false]);
        $course = $this->course();

        Sanctum::actingAs($this->student);
        $this->postJson('api/courses/'.$course->slug.'/reviews', ['rating' => 5])
            ->assertOk()->assertJsonPath('status', false); // not enrolled

        Enrollment::create(['user_id' => $this->student->id, 'course_id' => $course->id, 'source' => 'manual', 'status' => 'active', 'enrolled_at' => now()]);

        $this->postJson('api/courses/'.$course->slug.'/reviews', ['rating' => 6])->assertStatus(422);
        $review = $this->postJson('api/courses/'.$course->slug.'/reviews', ['rating' => 4, 'comment' => 'ok'])
            ->assertOk()->json('data');
        $this->assertSame('pending', $review['status']);
        $this->assertEquals(0, $course->fresh()->rating_count);

        Sanctum::actingAs($this->admin);
        $this->postJson('api/admin/reviews/'.$review['id'].'/approve')->assertOk();
        $this->assertEquals(1, $course->fresh()->rating_count);
        $this->assertEquals(4, (float) $course->fresh()->rating_avg);

        // Editing sends it back to moderation and drops it from the aggregate.
        Sanctum::actingAs($this->student);
        $this->postJson('api/courses/'.$course->slug.'/reviews', ['rating' => 1])->assertOk();
        $this->assertEquals(0, $course->fresh()->rating_count);
        $this->assertSame(1, \App\Models\CourseReview::count());
    }

    public function test_completion_auto_issues_one_certificate_and_it_can_be_verified(): void
    {
        $course = $this->course();
        $lesson = $course->lessons()->create(['title' => 'L1', 'type' => 'text', 'content' => 'x', 'is_published' => true]);
        $course->refreshLessonStats();

        Enrollment::create(['user_id' => $this->student->id, 'course_id' => $course->id, 'source' => 'manual', 'status' => 'active', 'enrolled_at' => now(), 'expires_at' => now()->addDays(5)]);

        Sanctum::actingAs($this->student);
        $this->postJson('api/lessons/'.$lesson->id.'/complete')->assertOk();
        $this->postJson('api/lessons/'.$lesson->id.'/complete')->assertOk();

        $this->assertSame(1, Certificate::count());
        $enrollment = Enrollment::first();
        $this->assertNotNull($enrollment->completed_at);
        $this->assertTrue($enrollment->certificate_eligible);

        $number = Certificate::first()->certificate_number;

        $this->getJson('api/certificates/'.$number.'/download')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson('api/my/dashboard')->assertOk()->assertJsonPath('data.stats.certificates', 1);

        // A different student can neither download nor view it, but anyone can verify it.
        Sanctum::actingAs(User::factory()->create(['type' => 'customer']));
        $this->getJson('api/certificates/'.$number.'/download')->assertOk()->assertJsonPath('status', false);

        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->getJson('api/certificates/verify/'.$number)->assertOk()->assertJsonPath('data.valid', true);
    }

    public function test_instructor_can_only_manage_own_courses(): void
    {
        $instructor = User::factory()->create(['type' => 'instructor']);
        $other = User::factory()->create(['type' => 'instructor']);
        $foreign = $this->course(['instructor_id' => $other->id]);

        Sanctum::actingAs($instructor);

        $created = $this->postJson('api/admin/courses/add', ['title' => 'Mine', 'price' => 100, 'status' => 'published'])
            ->assertOk()->json('data');
        $this->assertSame('under_review', $created['status'], 'Instructors cannot self-publish');
        $this->assertSame($instructor->id, $created['instructor_id']);

        $this->postJson('api/admin/lessons/add', ['course_id' => $foreign->id, 'title' => 'x', 'type' => 'text'])->assertStatus(403);
        $this->postJson('api/admin/courses/update', ['id' => $foreign->id, 'title' => 'hijack'])->assertStatus(403);
        $this->getJson('api/admin/courses')->assertOk()->assertJsonCount(1, 'data.data');

        // Admin-only areas stay closed.
        $this->getJson('api/admin/payments')->assertStatus(403);
        $this->getJson('api/admin/enrollments')->assertStatus(403);

        Sanctum::actingAs($this->student);
        $this->getJson('api/admin/courses')->assertStatus(403);
    }

    public function test_already_enrolled_student_cannot_buy_again(): void
    {
        $course = $this->course();
        Enrollment::create(['user_id' => $this->student->id, 'course_id' => $course->id, 'source' => 'manual', 'status' => 'active', 'enrolled_at' => now(), 'expires_at' => now()->addDays(5)]);

        Sanctum::actingAs($this->student);
        $this->postJson('api/cart/courses/add', ['course_id' => $course->id]);
        $this->postJson('api/orders/add', [])->assertOk()->assertJsonPath('status', false);
        $this->assertSame(0, Order::count());
    }

    public function test_course_attachments_are_locked_until_enrolled(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $course = $this->course();

        Sanctum::actingAs($this->admin);
        $pdf = $this->postJson('api/admin/lesson-resources/add', [
            'course_id' => $course->id,
            'title' => 'Workbook',
            'file' => \Illuminate\Http\UploadedFile::fake()->create('book.pdf', 100, 'application/pdf'),
        ])->assertOk()->json('data');
        $this->assertSame('pdf', $pdf['kind']);

        $this->postJson('api/admin/lesson-resources/add', [
            'course_id' => $course->id,
            'title' => 'Slides',
            'file' => \Illuminate\Http\UploadedFile::fake()->create('deck.pptx', 100),
        ])->assertOk()->assertJsonPath('data.kind', 'slides');

        $this->postJson('api/admin/lesson-resources/add', ['course_id' => $course->id, 'title' => 'x', 'file' => \Illuminate\Http\UploadedFile::fake()->create('evil.php', 1)])
            ->assertStatus(422);

        Sanctum::actingAs($this->student);
        $list = $this->getJson('api/courses/'.$course->slug.'/attachments')->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertTrue($list[0]['locked']);
        $this->assertNull($list[0]['download_url']);
        $this->getJson('api/lesson-resources/'.$pdf['id'].'/download')->assertOk()->assertJsonPath('status', false);

        Enrollment::create(['user_id' => $this->student->id, 'course_id' => $course->id, 'source' => 'manual', 'status' => 'active', 'enrolled_at' => now(), 'expires_at' => now()->addDays(5)]);

        $list = $this->getJson('api/courses/'.$course->slug.'/attachments')->json('data');
        $this->assertFalse($list[0]['locked']);
        $this->assertNotNull($list[0]['download_url']);
        $this->get('api/lesson-resources/'.$pdf['id'].'/download')->assertOk();
    }

    public function test_manual_mode_requires_admin_to_enroll_after_payment(): void
    {
        config(['courses.enrollment_mode' => 'manual']);
        $course = $this->course(['price' => 1000]);
        $order = $this->order($this->student, $course);

        Sanctum::actingAs($this->admin);
        $this->postJson('api/admin/orders/'.$order['id'].'/enroll')->assertOk()->assertJsonPath('status', false); // unpaid

        $this->postJson('api/admin/orders/'.$order['id'].'/confirm-payment')->assertOk();
        $this->assertSame('paid', Order::first()->payment_status);
        $this->assertNull(Enrollment::first(), 'Payment alone must not grant access');

        $this->postJson('api/admin/orders/update', ['id' => $order['id'], 'payment_status' => 'paid', 'status' => 'confirmed'])->assertOk();
        $this->assertNull(Enrollment::first(), 'Approving the order alone must not grant access');

        Sanctum::actingAs($this->student);
        $this->getJson('api/orders')->assertJsonPath('data.0.course_items.0.enrolled', false);

        Sanctum::actingAs($this->admin);
        $this->postJson('api/admin/orders/'.$order['id'].'/enroll')->assertOk()->assertJsonPath('status', true);
        $this->postJson('api/admin/orders/'.$order['id'].'/enroll')->assertOk();
        $this->assertSame(1, Enrollment::count());
        $this->assertTrue(Enrollment::first()->isActive());

        Sanctum::actingAs($this->student);
        $this->getJson('api/orders')->assertJsonPath('data.0.course_items.0.enrolled', true);
    }

    public function test_free_order_still_waits_for_admin_in_manual_mode(): void
    {
        config(['courses.enrollment_mode' => 'manual']);
        $this->order($this->student, $this->course(['price' => 0]));

        $this->assertNull(Enrollment::first());
    }

    public function test_course_orders_use_enrolled_status_and_never_shipped(): void
    {
        config(['courses.enrollment_mode' => 'manual']);
        $course = $this->course(['price' => 500]);
        $order = $this->order($this->student, $course);

        Sanctum::actingAs($this->admin);
        $this->postJson('api/admin/orders/update', ['id' => $order['id'], 'status' => 'shipped'])->assertStatus(422);
        $this->postJson('api/admin/orders/update', ['id' => $order['id'], 'status' => 'delivered'])->assertStatus(422);

        // Unpaid orders cannot be marked enrolled.
        $this->postJson('api/admin/orders/update', ['id' => $order['id'], 'status' => 'enrolled'])
            ->assertOk()->assertJsonPath('status', false);
        $this->assertNull(Enrollment::first());

        // Paid + enrolled in one save grants access and records the status.
        $this->postJson('api/admin/orders/update', ['id' => $order['id'], 'status' => 'enrolled', 'payment_status' => 'paid'])
            ->assertOk()->assertJsonPath('data.status', 'enrolled');
        $this->assertTrue(Enrollment::first()->isActive());

        // Cancelling revokes access.
        $this->postJson('api/admin/orders/update', ['id' => $order['id'], 'status' => 'cancelled'])->assertOk();
        $this->assertSame('cancelled', Enrollment::first()->status);
    }

    public function test_admin_enrollment_management(): void
    {
        $course = $this->course();
        Sanctum::actingAs($this->admin);

        $this->postJson('api/admin/enrollments/grant', ['user_id' => $this->student->id, 'course_id' => $course->id, 'access_days' => 10])->assertOk();
        $id = Enrollment::first()->id;
        $this->assertSame(1, $course->fresh()->total_students);
        $this->assertGreaterThan(0, $this->student->notifications()->count());

        $this->getJson('api/admin/enrollments?search='.urlencode($this->student->email))->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson('api/admin/enrollments?search=nobody@nowhere.test')->assertOk()->assertJsonCount(0, 'data.data');

        $this->postJson('api/admin/enrollments/revoke/'.$id)->assertOk();
        $this->assertSame(0, $course->fresh()->total_students);

        // Extending a cancelled enrollment must not silently revive it.
        $this->postJson('api/admin/enrollments/extend', ['id' => $id, 'days' => 5])->assertOk();
        $this->assertSame('cancelled', Enrollment::first()->status);

        $this->postJson('api/admin/enrollments/reactivate/'.$id)->assertOk();
        $this->assertTrue(Enrollment::first()->isActive());
    }

    public function test_admin_user_management_is_safe(): void
    {
        Sanctum::actingAs($this->admin);

        $created = $this->postJson('api/admin/users/add', ['name' => 'Ins', 'email' => 'ins@example.com', 'password' => 'secret12', 'type' => 'instructor'])
            ->assertOk()->json('data');
        $this->assertTrue((bool) User::find($created['id'])->is_active);

        $this->postJson('api/admin/users/add', ['name' => 'Bad', 'email' => 'bad@example.com', 'password' => 'secret12', 'type' => 'god'])->assertStatus(422);

        // Own account is protected.
        $this->deleteJson('api/admin/users/delete/'.$this->admin->id)->assertOk()->assertJsonPath('status', false);
        $this->postJson('api/admin/users/update', ['id' => $this->admin->id, 'name' => 'A', 'email' => $this->admin->email, 'type' => 'customer'])
            ->assertOk()->assertJsonPath('status', false);

        // Users with orders/enrollments cannot be deleted (it would cascade); deactivate instead.
        $course = $this->course();
        Enrollment::create(['user_id' => $this->student->id, 'course_id' => $course->id, 'source' => 'manual', 'status' => 'active', 'enrolled_at' => now()]);
        $this->deleteJson('api/admin/users/delete/'.$this->student->id)->assertOk()->assertJsonPath('status', false);
        $this->assertNotNull(User::find($this->student->id));

        $this->postJson('api/admin/users/update', ['id' => $this->student->id, 'name' => 'S', 'email' => $this->student->email, 'type' => 'customer', 'is_active' => false])->assertOk();
        $this->assertFalse((bool) $this->student->fresh()->is_active);

        $this->deleteJson('api/admin/users/delete/'.$created['id'])->assertOk()->assertJsonPath('status', true);
        $this->deleteJson('api/admin/users/delete/99999')->assertOk()->assertJsonPath('status', false);
    }
}
