<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveSession extends Model
{
    protected $fillable = [
        'course_id', 'created_by', 'title', 'description', 'provider', 'join_url', 'youtube_video_id',
        'scheduled_at', 'duration_minutes', 'status', 'started_at', 'ended_at', 'recording_url',
        'chat_enabled', 'chat_locked',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_minutes' => 'integer',
        'chat_enabled' => 'boolean',
        'chat_locked' => 'boolean',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isLive(): bool
    {
        return $this->status === 'live';
    }
}
