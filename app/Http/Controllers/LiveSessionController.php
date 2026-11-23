<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Course;
use App\Models\CoursePost;
use App\Models\LiveSession;
use App\Services\CommunityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Live classes (Zoom / Meet / YouTube Live / Facebook Live...) with a YouTube-style live chat.
 * Chat is polled (GET ?after=<last id>) so it works without a websocket server.
 */
class LiveSessionController extends Controller
{
    private const CHAT_MAX = 500;

    public function __construct(private readonly CommunityService $community)
    {
    }

    private function course(string $slug): ?Course
    {
        return Course::where('slug', $slug)->first();
    }

    private function deny()
    {
        return response()->json(['status' => false, 'message' => 'You need an active enrollment in this course', 'data' => []], 403);
    }

    /** Sessions of a course: live first, then upcoming, then past. */
    public function index(Request $request, $slug)
    {
        $course = $this->course($slug);

        if (! $course) {
            return $this->notFound('Course not found');
        }

        $role = $this->community->role($request->user(), $course);

        if (! $role) {
            return $this->deny();
        }

        $staff = $role === 'staff';

        $sessions = LiveSession::query()
            ->where('course_id', $course->id)
            ->when(! $staff, fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->get()
            ->sortBy(fn (LiveSession $s) => match ($s->status) {
                'live' => [0, $s->scheduled_at->timestamp],
                'scheduled' => [1, $s->scheduled_at->timestamp],
                default => [2, -$s->scheduled_at->timestamp],
            })
            ->take(60)
            ->values();

        return $this->success('Live classes retrieved successfully', [
            'role' => $role,
            'live_now' => $sessions->firstWhere('status', 'live')?->id,
            'sessions' => $sessions->map(fn (LiveSession $s) => $this->community->formatSession($s, true))->values(),
        ]);
    }

    public function store(Request $request, $slug)
    {
        $course = $this->course($slug);

        if (! $course) {
            return $this->notFound('Course not found');
        }

        if (! $this->canManageCourse($course)) {
            return $this->forbidden();
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'join_url' => 'required|string|max:2048',
            'scheduled_at' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:5|max:720',
            'chat_enabled' => 'nullable|boolean',
            'announce' => 'nullable|boolean',
        ]);

        $url = $this->community->cleanUrl($request->join_url, 'join_url');
        $provider = $this->community->providerOf($url);

        $session = DB::transaction(function () use ($request, $course, $url, $provider) {
            $session = LiveSession::create([
                'course_id' => $course->id,
                'created_by' => $request->user()->id,
                'title' => $request->title,
                'description' => $request->description,
                'provider' => $provider === 'link' ? 'other' : $provider,
                'join_url' => $url,
                'youtube_video_id' => $provider === 'youtube' ? $this->community->youtubeId($url) : null,
                'scheduled_at' => $request->scheduled_at,
                'duration_minutes' => (int) $request->input('duration_minutes', 60),
                'status' => 'scheduled',
                'chat_enabled' => $request->boolean('chat_enabled', true),
                'chat_locked' => false,
            ]);

            if ($request->boolean('announce', true)) {
                CoursePost::create([
                    'course_id' => $course->id,
                    'user_id' => $request->user()->id,
                    'live_session_id' => $session->id,
                    'title' => 'Live class: '.$session->title,
                    'body' => 'Next class: '.$session->title.' on '.$session->scheduled_at->format('D, M j \a\t g:i A').'.'.($request->description ? "\n".$request->description : ''),
                    'link_url' => $url,
                    'link_label' => 'Join the class',
                    'link_provider' => $provider,
                ]);
            }

            return $session;
        });

        $this->community->notifyStudents(
            $course,
            $request->user(),
            'live_scheduled',
            'Live class scheduled · '.$course->title,
            $session->title.' — '.$session->scheduled_at->format('D, M j \a\t g:i A'),
            ['session_id' => $session->id]
        );

        return $this->success('Live class scheduled', $this->community->formatSession($session, true));
    }

    public function update(Request $request, $id)
    {
        $session = LiveSession::with('course')->find($id);

        if (! $session) {
            return $this->notFound('Live class not found');
        }

        if (! $this->canManageCourse($session->course)) {
            return $this->forbidden();
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'join_url' => 'required|string|max:2048',
            'scheduled_at' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:5|max:720',
            'recording_url' => 'nullable|string|max:2048',
            'chat_enabled' => 'nullable|boolean',
        ]);

        $url = $this->community->cleanUrl($request->join_url, 'join_url');
        $provider = $this->community->providerOf($url);
        $rescheduled = $session->scheduled_at->ne(\Illuminate\Support\Carbon::parse($request->scheduled_at));

        $session->fill([
            'title' => $request->title,
            'description' => $request->description,
            'provider' => $provider === 'link' ? 'other' : $provider,
            'join_url' => $url,
            'youtube_video_id' => $provider === 'youtube' ? $this->community->youtubeId($url) : null,
            'scheduled_at' => $request->scheduled_at,
            'duration_minutes' => (int) $request->input('duration_minutes', $session->duration_minutes),
            'recording_url' => $this->community->cleanUrl($request->recording_url, 'recording_url'),
            'chat_enabled' => $request->has('chat_enabled') ? $request->boolean('chat_enabled') : $session->chat_enabled,
        ])->save();

        if ($rescheduled && $session->status === 'scheduled') {
            $this->community->notifyStudents($session->course, $request->user(), 'live_rescheduled', 'Class time changed · '.$session->course->title, $session->title.' — '.$session->scheduled_at->format('D, M j \a\t g:i A'), ['session_id' => $session->id]);
        }

        return $this->success('Live class updated', $this->community->formatSession($session, true));
    }

    public function destroy($id)
    {
        $session = LiveSession::with('course')->find($id);

        if (! $session) {
            return $this->notFound('Live class not found');
        }

        if (! $this->canManageCourse($session->course)) {
            return $this->forbidden();
        }

        if ($session->status === 'live') {
            return $this->error('End the live class before deleting it');
        }

        Comment::where('commentable_type', 'live')->where('commentable_id', $session->id)->forceDelete();
        $session->delete();

        return $this->success('Live class deleted', null);
    }

    public function start(Request $request, $id)
    {
        $session = LiveSession::with('course')->find($id);

        if (! $session) {
            return $this->notFound('Live class not found');
        }

        if (! $this->canManageCourse($session->course)) {
            return $this->forbidden();
        }

        if ($session->status === 'live') {
            return $this->success('Already live', $this->community->formatSession($session, true));
        }

        if (in_array($session->status, ['ended', 'cancelled'], true)) {
            return $this->error('This class has already finished');
        }

        if (LiveSession::where('course_id', $session->course_id)->where('status', 'live')->exists()) {
            return $this->error('Another class of this course is live. End it first.');
        }

        $session->forceFill(['status' => 'live', 'started_at' => now()])->save();

        $this->community->notifyStudents(
            $session->course,
            $request->user(),
            'live_started',
            'LIVE now · '.$session->course->title,
            $session->title.' just started — join now',
            ['session_id' => $session->id]
        );

        return $this->success('You are live', $this->community->formatSession($session, true));
    }

    public function end(Request $request, $id)
    {
        $session = LiveSession::with('course')->find($id);

        if (! $session) {
            return $this->notFound('Live class not found');
        }

        if (! $this->canManageCourse($session->course)) {
            return $this->forbidden();
        }

        $request->validate(['recording_url' => 'nullable|string|max:2048']);

        $session->forceFill([
            'status' => 'ended',
            'ended_at' => now(),
            'recording_url' => $request->filled('recording_url') ? $this->community->cleanUrl($request->recording_url, 'recording_url') : $session->recording_url,
        ])->save();

        return $this->success('Class ended', $this->community->formatSession($session, true));
    }

    public function cancel(Request $request, $id)
    {
        $session = LiveSession::with('course')->find($id);

        if (! $session) {
            return $this->notFound('Live class not found');
        }

        if (! $this->canManageCourse($session->course)) {
            return $this->forbidden();
        }

        if ($session->status !== 'scheduled') {
            return $this->error('Only upcoming classes can be cancelled');
        }

        $session->forceFill(['status' => 'cancelled'])->save();
        $this->community->notifyStudents($session->course, $request->user(), 'live_cancelled', 'Class cancelled · '.$session->course->title, $session->title.' was cancelled', ['session_id' => $session->id]);

        return $this->success('Class cancelled', $this->community->formatSession($session, true));
    }

    public function lockChat(Request $request, $id)
    {
        $session = LiveSession::with('course')->find($id);

        if (! $session) {
            return $this->notFound('Live class not found');
        }

        if (! $this->canManageCourse($session->course)) {
            return $this->forbidden();
        }

        $locked = $request->has('locked') ? $request->boolean('locked') : ! $session->chat_locked;
        $session->forceFill(['chat_locked' => $locked])->save();

        return $this->success($locked ? 'Chat locked (only you can write)' : 'Chat unlocked', ['chat_locked' => $locked]);
    }

    /*
    |--------------------------------------------------------------------------
    | Live chat
    |--------------------------------------------------------------------------
    */

    /** Poll: ?after=<last message id> returns only newer messages (oldest first). */
    public function chat(Request $request, $id)
    {
        $session = LiveSession::with('course')->find($id);

        if (! $session) {
            return $this->notFound('Live class not found');
        }

        $role = $this->community->role($request->user(), $session->course);

        if (! $role) {
            return $this->deny();
        }

        $after = (int) $request->query('after', 0);
        $base = Comment::query()->with('author')->where('commentable_type', 'live')->where('commentable_id', $session->id);

        $messages = $after > 0
            ? (clone $base)->where('id', '>', $after)->orderBy('id')->limit(100)->get()
            : (clone $base)->orderByDesc('id')->limit(50)->get()->reverse()->values();

        $removed = Comment::onlyTrashed()
            ->where('commentable_type', 'live')->where('commentable_id', $session->id)
            ->where('deleted_at', '>=', now()->subMinute())
            ->pluck('id');

        $pinned = (clone $base)->where('is_pinned', true)->latest('id')->first();

        $course = $session->course;
        $fmt = fn (Comment $c) => $this->community->formatComment($c, $course, [], false);

        return $this->success('Chat retrieved successfully', [
            'role' => $role,
            'session' => $this->community->formatSession($session, true),
            'messages' => $messages->map($fmt)->values(),
            'pinned' => $pinned ? $fmt($pinned) : null,
            'removed_ids' => $removed,
            'muted' => $role !== 'staff' && $this->community->isMuted($request->user(), $course),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function postChat(Request $request, $id)
    {
        $session = LiveSession::with('course')->find($id);

        if (! $session) {
            return $this->notFound('Live class not found');
        }

        $request->validate(['body' => 'required|string|max:'.self::CHAT_MAX]);

        $role = $this->community->role($request->user(), $session->course);

        if (! $role) {
            return $this->deny();
        }

        if (! $session->chat_enabled) {
            return $this->error('Chat is turned off for this class');
        }

        if (in_array($session->status, ['ended', 'cancelled'], true) && $role !== 'staff') {
            return $this->error('This class has ended');
        }

        if ($role !== 'staff') {
            if ($session->chat_locked) {
                return $this->error('Chat is locked by the instructor');
            }

            if ($this->community->isMuted($request->user(), $session->course)) {
                return $this->error('You cannot chat right now');
            }

            // Slow mode: one message per second per student.
            $recent = Comment::query()->where('commentable_type', 'live')->where('commentable_id', $session->id)
                ->where('user_id', $request->user()->id)->where('created_at', '>=', now()->subSecond())->exists();

            if ($recent) {
                return $this->error('You are sending messages too fast');
            }
        }

        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim($request->body)) ?? trim($request->body);

        $comment = Comment::create([
            'commentable_type' => 'live',
            'commentable_id' => $session->id,
            'user_id' => $request->user()->id,
            'body' => Str::limit($body, self::CHAT_MAX, ''),
        ]);

        return $this->success('Sent', $this->community->formatComment($comment->load('author'), $session->course, [], false));
    }
}
