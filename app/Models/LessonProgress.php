<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LessonProgress extends Model
{
    use HasFactory;

    protected $table = 'lesson_progress';

    protected $fillable = [
        'user_id',
        'course_id',
        'lesson_id',
        'completed_at',
        'last_position_seconds',
        'started_at',
        'last_activity_at',
        'max_position_seconds',
        'watched_seconds',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'last_position_seconds' => 'integer',
        'max_position_seconds' => 'integer',
        'watched_seconds' => 'integer',
        'started_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }
}
