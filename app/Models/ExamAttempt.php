<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamAttempt extends Model
{
    protected $fillable = [
        'exam_id', 'user_id', 'active_key', 'status', 'started_at', 'expires_at', 'submitted_at', 'answers',
        'question_order', 'settings', 'score', 'total_marks', 'percentage', 'correct_count', 'wrong_count',
        'unanswered_count', 'negative_marks_total', 'passed',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'submitted_at' => 'datetime',
        'answers' => 'array',
        'question_order' => 'array',
        'settings' => 'array',
        'score' => 'float',
        'total_marks' => 'float',
        'percentage' => 'float',
        'negative_marks_total' => 'float',
        'passed' => 'boolean',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isFinished(): bool
    {
        return $this->status !== 'in_progress';
    }
}
