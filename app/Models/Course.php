<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Course extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'course_category_id',
        'title',
        'slug',
        'subtitle',
        'description',
        'thumbnail',
        'promo_video_url',
        'level',
        'language',
        'price',
        'offer_price',
        'access_days',
        'status',
        'is_featured',
        'duration_minutes',
        'requirements',
        'outcomes',
        'total_lessons',
        'total_students',
        'rating_avg',
        'rating_count',
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
        'total_lessons' => 'integer',
        'total_students' => 'integer',
        'rating_avg' => 'decimal:2',
        'rating_count' => 'integer',
        'published_at' => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(CourseCategory::class, 'course_category_id');
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

    public function getEffectivePriceAttribute(): float
    {
        return (float) ($this->offer_price !== null ? $this->offer_price : $this->price);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail;
    }

    public function refreshRating(): void
    {
        $stats = $this->reviews()
            ->where('is_approved', true)
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
            'duration_minutes' => (int) ceil($this->publishedLessons()->sum('duration_seconds') / 60),
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
