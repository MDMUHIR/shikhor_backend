<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderCourse extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'course_id',
        'name',
        'slug',
        'image',
        'original_price',
        'unit_price',
        'discount',
        'line_total',
        'access_days',
    ];

    protected $casts = [
        'original_price' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'access_days' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
