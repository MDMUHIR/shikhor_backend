<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuizAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'user_id',
        'enrollment_id',
        'score',
        'total_marks',
        'percentage',
        'passed',
        'answers',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'score' => 'integer',
        'total_marks' => 'integer',
        'percentage' => 'decimal:2',
        'passed' => 'boolean',
        'answers' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
