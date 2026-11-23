<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\CommunityMute;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Like;
use App\Models\LiveSession;
use App\Models\User;
use App\Notifications\LmsNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/** Shared rules for course announcements, comments and live classes. */
class CommunityService
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly VideoUrlService $videos
    ) {
    }

    /** staff = admin or the course's instructor; student = actively enrolled; null = no access. */
    public function role(?User $user, Course $course): ?string
    {
        if (! $user || $user->is_active === false) {
            return null;
        }

        if ($user->isAdmin() || ($user->isInstructor() && (int) $course->instructor_id === $user->id)) {
            return 'staff';
        }

        return $this->access->hasActiveAccess($user, $course) ? 'student' : null;
    }

    public function isMuted(User $user, Course $course): bool
    {
        $mute = CommunityMute::where('course_id', $course->id)->where('user_id', $user->id)->first();

        return $mute !== null && $mute->isActive();
    }

    /**
     * Only plain https links are allowed (Zoom, Meet, YouTube, Facebook, WhatsApp, Telegram...).
     *
     * @throws ValidationException
     */
    public function cleanUrl(?string $url, string $field = 'link_url'): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (strlen($url) > 2048 || ! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || str_contains($url, ' ')) {
            throw ValidationException::withMessages([$field => ['Enter a valid https:// link']]);
        }

        return $url;
    }

    public function providerOf(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $host = strtolower(preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)));

        return match (true) {
            str_ends_with($host, 'zoom.us') || str_ends_with($host, 'zoom.com') => 'zoom',
            $host === 'meet.google.com' => 'meet',
            str_ends_with($host, 'youtube.com') || $host === 'youtu.be' || str_ends_with($host, 'youtube-nocookie.com') => 'youtube',
            str_ends_with($host, 'facebook.com') || $host === 'fb.watch' || $host === 'fb.me' || str_ends_with($host, 'fb.com') => 'facebook',
            str_ends_with($host, 'teams.microsoft.com') || str_ends_with($host, 'teams.live.com') => 'teams',
            $host === 't.me' || str_ends_with($host, 'telegram.org') => 'telegram',
            $host === 'wa.me' || str_ends_with($host, 'whatsapp.com') => 'whatsapp',
            str_ends_with($host, 'discord.gg') || str_ends_with($host, 'discord.com') => 'discord',
            default => 'link',
        };
    }

    public function youtubeId(?string $url): ?string
    {
        return $this->videos->youtubeId($url);
    }

    /** Tell every actively enrolled student (not the sender) about something new. */
    public function notifyStudents(Course $course, ?User $actor, string $kind, string $title, string $message, array $data = []): void
    {
        $userIds = Enrollment::query()
            ->active()
            ->where('course_id', $course->id)
            ->when($actor, fn ($q) => $q->where('user_id', '!=', $actor->id))
            ->pluck('user_id');

        User::query()->whereIn('id', $userIds)->chunkById(200, function ($users) use ($kind, $title, $message, $data, $course) {
            Notification::send($users, new LmsNotification($kind, $title, $message, $data + ['course_slug' => $course->slug]));
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Formatting
    |--------------------------------------------------------------------------
    */

    public function formatUser(?User $user, Course $course): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->avatar,
            'badge' => $user->isAdmin() ? 'admin' : ((int) $course->instructor_id === $user->id ? 'instructor' : null),
        ];
    }

    /** @param  array<int>  $likedIds  comment ids the viewer liked */
    public function formatComment(Comment $comment, Course $course, array $likedIds = [], bool $withReplies = true): array
    {
        $data = [
            'id' => $comment->id,
            'parent_id' => $comment->parent_id,
            'body' => $comment->body,
            'is_pinned' => $comment->is_pinned,
            'likes_count' => $comment->likes_count,
            'liked_by_me' => in_array($comment->id, $likedIds, true),
            'author' => $this->formatUser($comment->author, $course),
            'created_at' => $comment->created_at?->toIso8601String(),
        ];

        if ($withReplies) {
            $data['replies'] = $comment->relationLoaded('replies')
                ? $comment->replies->map(fn (Comment $r) => $this->formatComment($r, $course, $likedIds, false))->values()->all()
                : [];
        }

        return $data;
    }

    public function likedCommentIds(User $user, $comments): array
    {
        $ids = collect($comments)->flatMap(fn (Comment $c) => [$c->id, ...($c->relationLoaded('replies') ? $c->replies->pluck('id')->all() : [])])->all();

        return $ids === [] ? [] : Like::where('likeable_type', 'comment')->where('user_id', $user->id)->whereIn('likeable_id', $ids)->pluck('likeable_id')->all();
    }

    public function formatSession(LiveSession $session, bool $canJoin): array
    {
        return [
            'id' => $session->id,
            'course_id' => $session->course_id,
            'title' => $session->title,
            'description' => $session->description,
            'provider' => $session->provider,
            'status' => $session->status,
            'scheduled_at' => $session->scheduled_at?->toIso8601String(),
            'duration_minutes' => $session->duration_minutes,
            'started_at' => $session->started_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'chat_enabled' => $session->chat_enabled,
            'chat_locked' => $session->chat_locked,
            // Join links are private to enrolled students and staff.
            'join_url' => $canJoin ? $session->join_url : null,
            'embed_url' => $canJoin && $session->youtube_video_id ? $this->videos->youtubeEmbedUrl($session->youtube_video_id).'&autoplay=1' : null,
            'recording_url' => $canJoin ? $session->recording_url : null,
        ];
    }
}
