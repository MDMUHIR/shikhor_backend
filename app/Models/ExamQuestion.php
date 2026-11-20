<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamQuestion extends Model
{
    protected $fillable = [
        'exam_id', 'type', 'question', 'options', 'correct_answers', 'marks', 'negative_marks', 'explanation', 'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'correct_answers' => 'array',
        'marks' => 'float',
        'negative_marks' => 'float',
        'sort_order' => 'integer',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    /** Exact-match grading: every correct option and nothing else. */
    public function isCorrect(array $selected): bool
    {
        $selected = array_values(array_unique(array_map('strval', $selected)));
        $correct = array_values(array_unique(array_map('strval', $this->correct_answers ?? [])));

        sort($selected);
        sort($correct);

        return count($selected) > 0 && $selected === $correct;
    }
}
