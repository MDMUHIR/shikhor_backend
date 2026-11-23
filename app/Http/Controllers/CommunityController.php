<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\CommunityMute;
use App\Models\Course;
use App\Models\CoursePost;
use App\Models\Like;
use App\Services\CommunityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Course announcements (staff post, students read/comment/like) in the style of a Facebook group.
 */
class CommunityController extends Controller
{
    public function __construct(private readonly CommunityService $community)
    {
    }

    private function course(string $slug): ?Course
    {
        return Course::where('slug', $slug)->first();
    }

    private function deny(string $message = 'You need an active enrollment in this course')
    {
        return response()->json(['status' => false, 'message' => $message, 'data' => []], 403);
    }

    /** Resolve role for the signed-in user or return a 403 response. */
    private function roleOrDeny(Request $request, Course $course): string|\Illuminate\Http\JsonResponse
    {
        return $this->community->role($request->user(), $course) ?? $this->deny();
    }

    /*
    |--------------------------------------------------------------------------
    | Announcements
    |--------------------------------------------------------------------------
    */

    public function posts(Request $request, $slug)
    {
        $course = $this->course($slug);

        if (! $course) {
            return $this->notFound('Course not found');
        }

        $role = $this->roleOrDeny($request, $course);

        if (! is_string($role)) {
            return $role;
        }

        $posts = CoursePost::query()
            ->with(['author', 'session'])
            ->where('course_id', $course->id)
            ->orderByDesc('is_pinned')
            ->orderByDesc('pinned_at')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->query('per_page', 10), 1), 30));

        $ids = $posts->getCollection()->pluck('id');
        $commentCounts = Comment::query()->where('commentable_type', 'post')->whereIn('commentable_id', $ids)
            ->selectRaw('commentable_id, count(*) as c')->groupBy('commentable_id')->pluck('c', 'commentable_id');
        $likeCounts = Like::query()->where('likeable_type', 'post')->whereIn('likeable_id', $ids)
            ->selectRaw('likeable_id, count(*) as c')->groupBy('likeable_id')->pluck('c', 'likeable_id');
        $mine = Like::query()->where('likeable_type', 'post')->where('user_id', $request->user()->id)->whereIn('likeable_id', $ids)->pluck('likeable_id')->all();

        $posts->setCollection($posts->getCollection()->map(
            fn (CoursePost $p) => $this->format($p, $course, (int) ($likeCounts[$p->id] ?? 0), (int) ($commentCounts[$p->id] ?? 0), in_array($p->id, $mine, true))
        ));

        return $this->success('Announcements retrieved successfully', ['role' => $role, 'posts' => $posts]);
    }

    public function storePost(Request $request, $slug)
    {
        $course = $this->course($slug);

        if (! $course) {
            return $this->notFound('Course not found');
        }

        if (! $this->canManageCourse($course)) {
            return $this->forbidden();
        }

        $request->validate([
            'title' => 'nullable|string|max:255',
            'body' => 'required|string|max:5000',
            'link_url' => 'nullable|string|max:2048',
            'link_label' => 'nullable|string|max:120',
            'is_pinned' => 'nullable|boolean',
            'notify' => 'nullable|boolean',
        ]);

        $link = $this->community->cleanUrl($request->link_url);

        $post = DB::transaction(function () use ($request, $course, $link) {
            if ($request->boolean('is_pinned')) {
                // One pinned announcement at a time keeps the top of the feed clear.
                CoursePost::where('course_id', $course->id)->where('is_pinned', true)->update(['is_pinned' => false, 'pinned_at' => null]);
            }

            return CoursePost::create([
                'course_id' => $course->id,
                'user_id' => $request->user()->id,
                'title' => $request->title,
                'body' => $request->body,
                'link_url' => $link,
                'link_label' => $link ? ($request->link_label ?: 'Open link') : null,
                'link_provider' => $this->community->providerOf($link),
                'is_pinned' => $request->boolean('is_pinned'),
                'pinned_at' => $request->boolean('is_pinned') ? now() : null,
            ]);
        });

        if ($request->boolean('notify', true)) {
            $this->community->notifyStudents(
                $course,
                $request->user(),
                'course_announcement',
                'New announcement · '.$course->title,
                \Illuminate\Support\Str::limit($request->title ?: $request->body, 120),
                ['post_id' => $post->id]
            );
        }

        return $this->success('Announcement posted', $this->format($post->load('author'), $course, 0, 0, false));
    }

    public function updatePost(Request $request, $id)
    {
        $post = CoursePost::with('course')->find($id);

        if (! $post) {
            return $this->notFound('Announcement not found');
        }

        if (! $this->canManageCourse($post->course)) {
            return $this->forbidden();
        }

        $request->validate([
            'title' => 'nullable|string|max:255',
            'body' => 'required|string|max:5000',
            'link_url' => 'nullable|string|max:2048',
            'link_label' => 'nullable|string|max:120',
        ]);

        $link = $this->community->cleanUrl($request->link_url);

        $post->fill([
            'title' => $request->title,
            'body' => $request->body,
            'link_url' => $link,
            'link_label' => $link ? ($request->link_label ?: 'Open link') : null,
            'link_provider' => $this->community->providerOf($link),
        ])->save();

        return $this->success('Announcement updated', $this->format($post->load('author'), $post->course, 0, 0, false));
    }

    public function destroyPost($id)
    {
        $post = CoursePost::with('course')->find($id);

        if (! $post) {
            return $this->notFound('Announcement not found');
        }

        if (! $this->canManageCourse($post->course)) {
            return $this->forbidden();
        }

        Comment::where('commentable_type', 'post')->where('commentable_id', $post->id)->forceDelete();
        Like::where('likeable_type', 'post')->where('likeable_id', $post->id)->delete();
        $post->delete();

        return $this->success('Announcement deleted', null);
    }

    public function pinPost(Request $request, $id)
    {
        $post = CoursePost::with('course')->find($id);

        if (! $post) {
            return $this->notFound('Announcement not found');
        }

        if (! $this->canManageCourse($post->course)) {
            return $this->forbidden();
        }

        $pin = $request->has('pinned') ? $request->boolean('pinned') : ! $post->is_pinned;

        DB::transaction(function () use ($post, $pin) {
            if ($pin) {
                CoursePost::where('course_id', $post->course_id)->where('is_pinned', true)->update(['is_pinned' => false, 'pinned_at' => null]);
            }

            $post->forceFill(['is_pinned' => $pin, 'pinned_at' => $pin ? now() : null])->save();
        });

        return $this->success($pin ? 'Announcement pinned' : 'Announcement unpinned', ['is_pinned' => $pin]);
    }

    public function lockPost(Request $request, $id)
    {
        $post = CoursePost::with('course')->find($id);

        if (! $post) {
            return $this->notFound('Announcement not found');
        }

        if (! $this->canManageCourse($post->course)) {
            return $this->forbidden();
        }

        $locked = $request->has('locked') ? $request->boolean('locked') : ! $post->comments_locked;
        $post->forceFill(['comments_locked' => $locked])->save();

        return $this->success($locked ? 'Comments turned off' : 'Comments turned on', ['comments_locked' => $locked]);
    }

    /*
    |--------------------------------------------------------------------------
    | Comments (announcements)
    |--------------------------------------------------------------------------
    */

    public function comments(Request $request, $postId)
    {
        $post = CoursePost::with('course')->find($postId);

        if (! $post) {
            return $this->notFound('Announcement not found');
        }

        $role = $this->roleOrDeny($request, $post->course);

        if (! is_string($role)) {
            return $role;
        }

        $comments = Comment::query()
            ->with(['author', 'replies.author'])
            ->where('commentable_type', 'post')
            ->where('commentable_id', $post->id)
            ->whereNull('parent_id')
            ->orderByDesc('is_pinned')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->query('per_page', 8), 1), 30));

        $liked = $this->community->likedCommentIds($request->user(), $comments->getCollection());
        $comments->setCollection($comments->getCollection()->map(fn (Comment $c) => $this->community->formatComment($c, $post->course, $liked)));

        return $this->success('Comments retrieved successfully', $comments);
    }

    public function storeComment(Request $request, $postId)
    {
        $post = CoursePost::with('course')->find($postId);

        if (! $post) {
            return $this->notFound('Announcement not found');
        }

        $request->validate([
            'body' => 'required|string|max:1000',
            'parent_id' => 'nullable|integer',
        ]);

        $role = $this->roleOrDeny($request, $post->course);

        if (! is_string($role)) {
            return $role;
        }

        if ($post->comments_locked && $role !== 'staff') {
            return $this->error('Comments are turned off for this announcement');
        }

        if ($role !== 'staff' && $this->community->isMuted($request->user(), $post->course)) {
            return $this->error('You cannot comment right now');
        }

        $parentId = null;

        if ($request->filled('parent_id')) {
            $parent = Comment::where('commentable_type', 'post')->where('commentable_id', $post->id)->whereNull('parent_id')->find($request->parent_id);

            if (! $parent) {
                return $this->error('The comment you are replying to no longer exists');
            }

            $parentId = $parent->id;
        }

        $comment = Comment::create([
            'commentable_type' => 'post',
            'commentable_id' => $post->id,
            'user_id' => $request->user()->id,
            'parent_id' => $parentId,
            'body' => $this->cleanBody($request->body),
        ]);

        return $this->success('Comment added', $this->community->formatComment($comment->load('author'), $post->course, [], false));
    }

    public function destroyComment(Request $request, $id)
    {
        $comment = Comment::find($id);

        if (! $comment) {
            return $this->notFound('Comment not found');
        }

        $course = $this->courseOfComment($comment);

        if (! $course) {
            return $this->notFound('Comment not found');
        }

        $isStaff = $this->canManageCourse($course);

        if (! $isStaff && $comment->user_id !== $request->user()->id) {
            return $this->forbidden();
        }

        $comment->delete();

        return $this->success('Comment deleted', null);
    }

    public function pinComment(Request $request, $id)
    {
        $comment = Comment::find($id);

        if (! $comment) {
            return $this->notFound('Comment not found');
        }

        $course = $this->courseOfComment($comment);

        if (! $course || ! $this->canManageCourse($course)) {
            return $this->forbidden();
        }

        $pin = $request->has('pinned') ? $request->boolean('pinned') : ! $comment->is_pinned;

        DB::transaction(function () use ($comment, $pin) {
            if ($pin) {
                Comment::where('commentable_type', $comment->commentable_type)->where('commentable_id', $comment->commentable_id)->where('is_pinned', true)->update(['is_pinned' => false]);
            }

            $comment->forceFill(['is_pinned' => $pin])->save();
        });

        return $this->success($pin ? 'Comment pinned' : 'Comment unpinned', ['is_pinned' => $pin]);
    }

    /*
    |--------------------------------------------------------------------------
    | Likes
    |--------------------------------------------------------------------------
    */

    public function likePost(Request $request, $id)
    {
        $post = CoursePost::with('course')->find($id);

        if (! $post) {
            return $this->notFound('Announcement not found');
        }

        $role = $this->roleOrDeny($request, $post->course);

        if (! is_string($role)) {
            return $role;
        }

        [$liked, $count] = $this->toggleLike('post', $post->id, $request->user()->id);

        return $this->success($liked ? 'Liked' : 'Like removed', ['liked' => $liked, 'likes_count' => $count]);
    }

    public function likeComment(Request $request, $id)
    {
        $comment = Comment::find($id);
        $course = $comment ? $this->courseOfComment($comment) : null;

        if (! $comment || ! $course) {
            return $this->notFound('Comment not found');
        }

        $role = $this->roleOrDeny($request, $course);

        if (! is_string($role)) {
            return $role;
        }

        [$liked, $count] = $this->toggleLike('comment', $comment->id, $request->user()->id);
        $comment->forceFill(['likes_count' => $count])->saveQuietly();

        return $this->success($liked ? 'Liked' : 'Like removed', ['liked' => $liked, 'likes_count' => $count]);
    }

    private function toggleLike(string $type, int $id, int $userId): array
    {
        return DB::transaction(function () use ($type, $id, $userId) {
            $existing = Like::where(['likeable_type' => $type, 'likeable_id' => $id, 'user_id' => $userId])->first();

            if ($existing) {
                $existing->delete();
                $liked = false;
            } else {
                Like::firstOrCreate(['likeable_type' => $type, 'likeable_id' => $id, 'user_id' => $userId]);
                $liked = true;
            }

            return [$liked, Like::where('likeable_type', $type)->where('likeable_id', $id)->count()];
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Moderation
    |--------------------------------------------------------------------------
    */

    public function mute(Request $request, $slug)
    {
        $course = $this->course($slug);

        if (! $course) {
            return $this->notFound('Course not found');
        }

        if (! $this->canManageCourse($course)) {
            return $this->forbidden();
        }

        $request->validate(['user_id' => 'required|exists:users,id', 'minutes' => 'nullable|integer|min:1|max:525600']);

        $target = \App\Models\User::find($request->user_id);

        if ($target->isAdmin() || (int) $course->instructor_id === $target->id) {
            return $this->error('Staff cannot be muted');
        }

        CommunityMute::updateOrCreate(
            ['course_id' => $course->id, 'user_id' => $target->id],
            ['muted_by' => $request->user()->id, 'until' => $request->filled('minutes') ? now()->addMinutes((int) $request->minutes) : null]
        );

        return $this->success($target->name.' was muted', null);
    }

    public function unmute(Request $request, $slug)
    {
        $course = $this->course($slug);

        if (! $course) {
            return $this->notFound('Course not found');
        }

        if (! $this->canManageCourse($course)) {
            return $this->forbidden();
        }

        $request->validate(['user_id' => 'required|integer']);
        CommunityMute::where('course_id', $course->id)->where('user_id', $request->user_id)->delete();

        return $this->success('Student unmuted', null);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function cleanBody(string $body): string
    {
        // Plain text only; the frontend escapes it. Strip control characters and collapse huge blank runs.
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? $body;
        $body = preg_replace("/\n{3,}/", "\n\n", trim($body)) ?? trim($body);

        return $body;
    }

    private function courseOfComment(Comment $comment): ?Course
    {
        if ($comment->commentable_type === 'post') {
            return CoursePost::with('course')->find($comment->commentable_id)?->course;
        }

        return \App\Models\LiveSession::with('course')->find($comment->commentable_id)?->course;
    }

    private function format(CoursePost $post, Course $course, int $likes, int $comments, bool $liked): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'body' => $post->body,
            'link_url' => $post->link_url,
            'link_label' => $post->link_label,
            'link_provider' => $post->link_provider,
            'is_pinned' => $post->is_pinned,
            'comments_locked' => $post->comments_locked,
            'live_session_id' => $post->live_session_id,
            'author' => $this->community->formatUser($post->author, $course),
            'likes_count' => $likes,
            'comments_count' => $comments,
            'liked_by_me' => $liked,
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }
}
