<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Enrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'order_id',
        'source',
        'status',
        'enrolled_at',
        'starts_at',
        'expires_at',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && ($this->starts_at === null || $this->starts_at->isPast() || $this->starts_at->equalTo(now()))
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isLifetime(): bool
    {
        return $this->expires_at === null;
    }

    public function remainingDays(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return max(0, (int) now()->diffInDays($this->expires_at, false));
    }
}
