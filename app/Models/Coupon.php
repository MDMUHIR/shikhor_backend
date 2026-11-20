<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'discount',
        'is_active',
        'starts_at',
        'expires_at',
        'max_discount',
        'min_purchase',
        'usage_limit',
        'per_user_limit',
        'applies_to',
    ];

    protected $casts = [
        'discount' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'min_purchase' => 'decimal:2',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'usage_limit' => 'integer',
        'per_user_limit' => 'integer',
        'times_used' => 'integer',
    ];

    public function courses()
    {
        return $this->belongsToMany(Course::class, 'coupon_courses');
    }

    public function courseCategories()
    {
        return $this->belongsToMany(CourseCategory::class, 'coupon_course_categories');
    }

    public function usages()
    {
        return $this->hasMany(CouponUsage::class);
    }
}
