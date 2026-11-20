<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    protected $fillable = [
        'course_id', 'title', 'description', 'instructions', 'duration_minutes', 'pass_percentage', 'max_attempts',
        'negative_marking', 'negative_marking_type', 'negative_marking_value', 'allow_negative_total',
        'shuffle_questions', 'shuffle_options', 'show_answers', 'starts_at', 'ends_at', 'is_published',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'pass_percentage' => 'integer',
        'max_attempts' => 'integer',
        'negative_marking' => 'boolean',
        'negative_marking_value' => 'float',
        'allow_negative_total' => 'boolean',
        'shuffle_questions' => 'boolean',
        'shuffle_options' => 'boolean',
        'is_published' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function questions()
    {
        return $this->hasMany(ExamQuestion::class)->orderBy('sort_order')->orderBy('id');
    }

    public function attempts()
    {
        return $this->hasMany(ExamAttempt::class);
    }

    public function totalMarks(): float
    {
        return (float) $this->questions()->sum('marks');
    }

    /** upcoming | open | closed */
    public function windowState(): string
    {
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'upcoming';
        }

        if ($this->ends_at && $this->ends_at->isPast()) {
            return 'closed';
        }

        return 'open';
    }

    /** Marking rules frozen into each attempt so later edits never change old results. */
    public function markingRules(): array
    {
        return [
            'negative_marking' => (bool) $this->negative_marking,
            'type' => $this->negative_marking_type,
            'value' => (float) $this->negative_marking_value,
            'allow_negative_total' => (bool) $this->allow_negative_total,
            'pass_percentage' => (int) $this->pass_percentage,
        ];
    }
}
