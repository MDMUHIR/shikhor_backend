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
        'completed_at',
        'progress_percentage',
        'last_lesson_id',
        'last_accessed_at',
        'certificate_eligible',
    ];

    public const STATUSES = ['active', 'completed', 'expired', 'suspended', 'cancelled'];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'progress_percentage' => 'decimal:2',
        'certificate_eligible' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function lastLesson()
    {
        return $this->belongsTo(Lesson::class, 'last_lesson_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'completed'])
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'completed'], true)
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
