<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuizQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'type',
        'question',
        'options',
        'correct_answers',
        'marks',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'correct_answers' => 'array',
        'marks' => 'integer',
        'sort_order' => 'integer',
    ];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    public function isCorrect(array $selected): bool
    {
        $selected = array_values(array_unique(array_map('strval', $selected)));
        $correct = array_values(array_unique(array_map('strval', $this->correct_answers ?? [])));

        sort($selected);
        sort($correct);

        return count($selected) > 0 && $selected === $correct;
    }
}
