<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Course extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['draft', 'under_review', 'published', 'unpublished', 'archived'];

    protected $fillable = [
        'course_category_id',
        'instructor_id',
        'title',
        'slug',
        'subtitle',
        'short_description',
        'description',
        'thumbnail',
        'banner',
        'promo_video_url',
        'promo_video_source',
        'promo_video_path',
        'level',
        'language',
        'price',
        'offer_price',
        'discount_starts_at',
        'discount_ends_at',
        'access_days',
        'status',
        'is_featured',
        'duration_minutes',
        'estimated_completion_minutes',
        'requirements',
        'outcomes',
        'target_audience',
        'tags',
        'certificate_enabled',
        'completion_percentage_required',
        'require_quizzes_passed',
        'total_lessons',
        'total_sections',
        'total_students',
        'rating_avg',
        'rating_count',
        'seo_title',
        'seo_description',
        'seo_meta',
        'published_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'offer_price' => 'decimal:2',
        'access_days' => 'integer',
        'is_featured' => 'boolean',
        'duration_minutes' => 'integer',
        'requirements' => 'array',
        'outcomes' => 'array',
        'target_audience' => 'array',
        'tags' => 'array',
        'seo_meta' => 'array',
        'certificate_enabled' => 'boolean',
        'require_quizzes_passed' => 'boolean',
        'completion_percentage_required' => 'integer',
        'total_lessons' => 'integer',
        'total_sections' => 'integer',
        'total_students' => 'integer',
        'rating_avg' => 'decimal:2',
        'rating_count' => 'integer',
        'discount_starts_at' => 'datetime',
        'discount_ends_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    protected $hidden = ['promo_video_path'];

    public function category()
    {
        return $this->belongsTo(CourseCategory::class, 'course_category_id');
    }

    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function sections()
    {
        return $this->hasMany(CourseSection::class)->orderBy('sort_order')->orderBy('id');
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class)->orderBy('sort_order')->orderBy('id');
    }

    public function publishedLessons()
    {
        return $this->lessons()->where('is_published', true);
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function reviews()
    {
        return $this->hasMany(CourseReview::class);
    }

    public function quizzes()
    {
        return $this->hasMany(Quiz::class)->orderBy('sort_order')->orderBy('id');
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }

    public function orderCourses()
    {
        return $this->hasMany(OrderCourse::class);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->where(function ($q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function isPublished(): bool
    {
        return $this->status === 'published'
            && ($this->published_at === null || $this->published_at->isPast());
    }

    public function discountIsActive(): bool
    {
        if ($this->offer_price === null) {
            return false;
        }

        return ($this->discount_starts_at === null || $this->discount_starts_at->isPast())
            && ($this->discount_ends_at === null || $this->discount_ends_at->isFuture());
    }

    public function getEffectivePriceAttribute(): float
    {
        return (float) ($this->discountIsActive() ? $this->offer_price : $this->price);
    }

    public function isFree(): bool
    {
        return $this->effective_price <= 0;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail;
    }

    /** Recalculate the cached rating summary from approved reviews only. */
    public function refreshRating(): void
    {
        $stats = $this->reviews()
            ->where('status', 'approved')
            ->selectRaw('COUNT(*) as review_count, COALESCE(AVG(rating), 0) as review_avg')
            ->first();

        $this->forceFill([
            'rating_count' => (int) ($stats->review_count ?? 0),
            'rating_avg' => round((float) ($stats->review_avg ?? 0), 2),
        ])->saveQuietly();
    }

    public function refreshLessonStats(): void
    {
        $this->forceFill([
            'total_lessons' => $this->publishedLessons()->count(),
            'total_sections' => $this->sections()->count(),
            'duration_minutes' => (int) ceil($this->publishedLessons()->sum('duration_seconds') / 60),
        ])->saveQuietly();
    }

    public function refreshStudentCount(): void
    {
        $this->forceFill([
            'total_students' => $this->enrollments()->whereIn('status', ['active', 'completed', 'expired'])->count(),
        ])->saveQuietly();
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'course';
        $slug = $base;
        $i = 1;

        while (static::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
