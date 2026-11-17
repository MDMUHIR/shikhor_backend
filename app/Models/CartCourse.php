<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartCourse extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'price',
        'is_selected',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_selected' => 'boolean',
    ];

    protected $attributes = [
        'is_selected' => true,
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
