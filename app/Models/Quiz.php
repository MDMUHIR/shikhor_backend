<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quiz extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'lesson_id',
        'title',
        'description',
        'pass_percentage',
        'time_limit_minutes',
        'max_attempts',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'pass_percentage' => 'integer',
        'time_limit_minutes' => 'integer',
        'max_attempts' => 'integer',
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function questions()
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('sort_order')->orderBy('id');
    }

    public function attempts()
    {
        return $this->hasMany(QuizAttempt::class);
    }
}
