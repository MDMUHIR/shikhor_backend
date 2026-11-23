<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoursePost extends Model
{
    protected $fillable = [
        'course_id', 'user_id', 'live_session_id', 'title', 'body', 'link_url', 'link_label', 'link_provider',
        'is_pinned', 'pinned_at', 'comments_locked',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'pinned_at' => 'datetime',
        'comments_locked' => 'boolean',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function session()
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'commentable_id')->where('commentable_type', 'post');
    }
}
