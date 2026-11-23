<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $student;

    private User $outsider;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['type' => 'admin']);
        $this->teacher = User::factory()->create(['type' => 'instructor']);
        $this->student = User::factory()->create(['type' => 'customer']);
        $this->outsider = User::factory()->create(['type' => 'customer']);

        $this->course = Course::create([
            'title' => 'IELTS', 'slug' => 'ielts', 'price' => 100, 'status' => 'published',
            'published_at' => now()->subDay(), 'instructor_id' => $this->teacher->id,
        ]);

        Enrollment::create(['user_id' => $this->student->id, 'course_id' => $this->course->id, 'source' => 'manual', 'status' => 'active', 'enrolled_at' => now(), 'expires_at' => now()->addDays(10)]);
    }

    public function test_only_staff_post_and_only_enrolled_students_read_announcements(): void
    {
        Sanctum::actingAs($this->student);
        $this->postJson('api/courses/ielts/posts', ['body' => 'hi'])->assertStatus(403);

        Sanctum::actingAs($this->teacher);
        $post = $this->postJson('api/courses/ielts/posts', [
            'title' => 'Class tomorrow', 'body' => 'Next class tomorrow 8 PM', 'link_url' => 'https://chat.whatsapp.com/abc', 'link_label' => 'WhatsApp group', 'is_pinned' => true,
        ])->assertOk()->json('data');
        $this->assertSame('whatsapp', $post['link_provider']);
        $this->assertSame('instructor', $post['author']['badge']);
        $this->assertTrue($post['is_pinned']);

        // Unsafe links are refused
        $this->postJson('api/courses/ielts/posts', ['body' => 'x', 'link_url' => 'javascript:alert(1)'])->assertStatus(422);
        $this->postJson('api/courses/ielts/posts', ['body' => 'x', 'link_url' => 'http://insecure.example'])->assertStatus(422);

        // A second pinned post replaces the first
        $second = $this->postJson('api/courses/ielts/posts', ['body' => 'Pinned now', 'is_pinned' => true])->json('data.id');
        $feed = $this->getJson('api/courses/ielts/posts')->assertOk()->json('data.posts.data');
        $this->assertSame($second, $feed[0]['id']);
        $this->assertFalse(collect($feed)->firstWhere('id', $post['id'])['is_pinned']);

        // Students read; outsiders do not
        Sanctum::actingAs($this->student);
        $this->getJson('api/courses/ielts/posts')->assertOk()->assertJsonPath('data.role', 'student');
        $this->assertGreaterThan(0, $this->student->notifications()->count(), 'enrolled students are notified');

        Sanctum::actingAs($this->outsider);
        $this->getJson('api/courses/ielts/posts')->assertStatus(403);

        // Another instructor cannot post on this course
        Sanctum::actingAs(User::factory()->create(['type' => 'instructor']));
        $this->postJson('api/courses/ielts/posts', ['body' => 'hijack'])->assertStatus(403);

        // Admin can
        Sanctum::actingAs($this->admin);
        $this->postJson('api/courses/ielts/posts', ['body' => 'from admin'])->assertOk()->assertJsonPath('data.author.badge', 'admin');
    }

    public function test_comments_replies_likes_and_moderation(): void
    {
        Sanctum::actingAs($this->teacher);
        $postId = $this->postJson('api/courses/ielts/posts', ['body' => 'Hello class'])->json('data.id');

        Sanctum::actingAs($this->student);
        $c = $this->postJson("api/posts/$postId/comments", ['body' => 'Thank you sir!'])->assertOk()->json('data');
        $reply = $this->postJson("api/posts/$postId/comments", ['body' => 'me too', 'parent_id' => $c['id']])->assertOk()->json('data');
        $this->assertSame($c['id'], $reply['parent_id']);
        $this->postJson("api/posts/$postId/comments", ['body' => '   '])->assertStatus(422);

        $this->postJson("api/comments/{$c['id']}/like")->assertJsonPath('data.liked', true)->assertJsonPath('data.likes_count', 1);
        $this->postJson("api/comments/{$c['id']}/like")->assertJsonPath('data.liked', false)->assertJsonPath('data.likes_count', 0);
        $this->postJson("api/posts/$postId/like")->assertJsonPath('data.likes_count', 1);

        $list = $this->getJson("api/posts/$postId/comments")->assertOk()->json('data.data');
        $this->assertCount(1, $list);
        $this->assertCount(1, $list[0]['replies']);

        $feed = $this->getJson('api/courses/ielts/posts')->json('data.posts.data.0');
        $this->assertSame(2, $feed['comments_count']);
        $this->assertTrue($feed['liked_by_me']);

        // Outsiders cannot comment; a student cannot delete someone else's comment
        Sanctum::actingAs($this->outsider);
        $this->postJson("api/posts/$postId/comments", ['body' => 'hi'])->assertStatus(403);

        // Locked comments: students blocked, staff allowed
        Sanctum::actingAs($this->teacher);
        $this->postJson("api/posts/$postId/lock-comments")->assertOk();
        $this->postJson("api/posts/$postId/comments", ['body' => 'Teacher can still reply'])->assertOk()->assertJsonPath('data.author.badge', 'instructor');
        Sanctum::actingAs($this->student);
        $this->postJson("api/posts/$postId/comments", ['body' => 'blocked'])->assertOk()->assertJsonPath('status', false);

        // Mute
        Sanctum::actingAs($this->teacher);
        $this->postJson("api/posts/$postId/lock-comments")->assertOk(); // unlock
        $this->postJson('api/courses/ielts/mute', ['user_id' => $this->student->id, 'minutes' => 30])->assertOk();
        Sanctum::actingAs($this->student);
        $this->postJson("api/posts/$postId/comments", ['body' => 'muted?'])->assertOk()->assertJsonPath('status', false);
        Sanctum::actingAs($this->teacher);
        $this->postJson('api/courses/ielts/unmute', ['user_id' => $this->student->id])->assertOk();
        Sanctum::actingAs($this->student);
        $this->postJson("api/posts/$postId/comments", ['body' => 'back'])->assertOk()->assertJsonPath('status', true);

        // Staff can delete any comment, authors their own
        Sanctum::actingAs($this->teacher);
        $this->deleteJson("api/comments/{$c['id']}")->assertOk();
        $this->assertSoftDeleted('comments', ['id' => $c['id']]);
        Sanctum::actingAs($this->student);
        $own = Comment::where('body', 'back')->first();
        $this->deleteJson("api/comments/{$own->id}")->assertOk();
        $teachers = Comment::where('body', 'Teacher can still reply')->first();
        $this->deleteJson("api/comments/{$teachers->id}")->assertStatus(403);
    }

    public function test_live_class_lifecycle_privacy_and_chat(): void
    {
        Sanctum::actingAs($this->teacher);
        $session = $this->postJson('api/courses/ielts/live-sessions', [
            'title' => 'Writing Task 2', 'join_url' => 'https://us02web.zoom.us/j/123456789?pwd=abc', 'scheduled_at' => now()->addDay()->toIso8601String(), 'duration_minutes' => 90,
        ])->assertOk()->json('data');
        $this->assertSame('zoom', $session['provider']);
        $this->assertSame('scheduled', $session['status']);

        $this->postJson('api/courses/ielts/live-sessions', ['title' => 'x', 'join_url' => 'http://zoom.us/j/1', 'scheduled_at' => now()->addDay()->toIso8601String()])->assertStatus(422);

        // The announcement for the class was created and students were notified
        $this->assertSame(1, \App\Models\CoursePost::where('live_session_id', $session['id'])->count());

        // Outsiders never see the join link
        Sanctum::actingAs($this->outsider);
        $this->getJson('api/courses/ielts/live-sessions')->assertStatus(403);
        $this->getJson("api/live-sessions/{$session['id']}/chat")->assertStatus(403);

        // Students see the link, but cannot manage
        Sanctum::actingAs($this->student);
        $list = $this->getJson('api/courses/ielts/live-sessions')->assertOk()->json('data');
        $this->assertSame('https://us02web.zoom.us/j/123456789?pwd=abc', $list['sessions'][0]['join_url']);
        $this->postJson("api/live-sessions/{$session['id']}/start")->assertStatus(403);

        // Go live
        Sanctum::actingAs($this->teacher);
        $this->postJson("api/live-sessions/{$session['id']}/start")->assertOk()->assertJsonPath('data.status', 'live');

        $other = LiveSession::create(['course_id' => $this->course->id, 'title' => 'Second', 'join_url' => 'https://meet.google.com/abc-defg-hij', 'scheduled_at' => now(), 'status' => 'scheduled']);
        $this->postJson("api/live-sessions/{$other->id}/start")->assertOk()->assertJsonPath('status', false); // only one live at a time

        // Live chat
        Sanctum::actingAs($this->student);
        $m1 = $this->postJson("api/live-sessions/{$session['id']}/chat", ['body' => 'Hello sir 👋'])->assertOk()->json('data');
        $this->assertSame('Hello sir 👋', $m1['body']);
        $this->travel(2)->seconds();
        $this->postJson("api/live-sessions/{$session['id']}/chat", ['body' => 'Audio is clear'])->assertOk();

        $poll = $this->getJson("api/live-sessions/{$session['id']}/chat")->assertOk()->json('data');
        $this->assertCount(2, $poll['messages']);
        $last = $poll['messages'][1]['id'];

        Sanctum::actingAs($this->teacher);
        $this->postJson("api/live-sessions/{$session['id']}/chat", ['body' => 'Welcome everyone'])->assertOk()->assertJsonPath('data.author.badge', 'instructor');

        Sanctum::actingAs($this->student);
        $newer = $this->getJson("api/live-sessions/{$session['id']}/chat?after=$last")->json('data');
        $this->assertCount(1, $newer['messages']);
        $this->assertSame('Welcome everyone', $newer['messages'][0]['body']);

        // Teacher pins a message and removes another; polls learn about both
        Sanctum::actingAs($this->teacher);
        $this->postJson("api/comments/{$m1['id']}/pin")->assertOk();
        $this->deleteJson('api/comments/'.$poll['messages'][1]['id'])->assertOk();
        Sanctum::actingAs($this->student);
        $after = $this->getJson("api/live-sessions/{$session['id']}/chat?after=$last")->json('data');
        $this->assertSame($m1['id'], $after['pinned']['id']);
        $this->assertContains($poll['messages'][1]['id'], $after['removed_ids']);

        // Locked chat blocks students only
        Sanctum::actingAs($this->teacher);
        $this->postJson("api/live-sessions/{$session['id']}/lock-chat")->assertOk()->assertJsonPath('data.chat_locked', true);
        Sanctum::actingAs($this->student);
        $this->travel(2)->seconds();
        $this->postJson("api/live-sessions/{$session['id']}/chat", ['body' => 'can I talk?'])->assertOk()->assertJsonPath('status', false);

        // End + recording; students can no longer chat, link stays for the recording
        Sanctum::actingAs($this->teacher);
        $this->postJson("api/live-sessions/{$session['id']}/end", ['recording_url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertOk()->assertJsonPath('data.status', 'ended');
        Sanctum::actingAs($this->student);
        $this->postJson("api/live-sessions/{$session['id']}/chat", ['body' => 'late'])->assertOk()->assertJsonPath('status', false);
        $ended = collect($this->getJson('api/courses/ielts/live-sessions')->json('data.sessions'))->firstWhere('id', $session['id']);
        $this->assertSame('https://youtu.be/dQw4w9WgXcQ', $ended['recording_url']);
    }

    public function test_youtube_live_is_embeddable_and_slow_mode_limits_spam(): void
    {
        Sanctum::actingAs($this->teacher);
        $session = $this->postJson('api/courses/ielts/live-sessions', [
            'title' => 'YT live', 'join_url' => 'https://www.youtube.com/live/dQw4w9WgXcQ', 'scheduled_at' => now()->toIso8601String(), 'announce' => false,
        ])->assertOk()->json('data');

        $this->assertSame('youtube', $session['provider']);
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $session['embed_url']);
        $this->assertSame(0, \App\Models\CoursePost::count());

        $this->postJson("api/live-sessions/{$session['id']}/start")->assertOk();

        Sanctum::actingAs($this->student);
        $this->postJson("api/live-sessions/{$session['id']}/chat", ['body' => 'one'])->assertOk()->assertJsonPath('status', true);
        $this->postJson("api/live-sessions/{$session['id']}/chat", ['body' => 'two'])->assertOk()->assertJsonPath('status', false); // within 1 second
        $this->postJson("api/live-sessions/{$session['id']}/chat", ['body' => str_repeat('a', 501)])->assertStatus(422);
    }
}
