<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'course_section_id',
        'title',
        'slug',
        'description',
        'type',
        'video_source',
        'video_url',
        'video_path',
        'duration_seconds',
        'content',
        'is_preview',
        'is_published',
        'sort_order',
        'video_external_id',
        'video_thumbnail',
        'video_mime',
        'video_size',
        'video_status',
        'completion_threshold',
        'is_required',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'is_preview' => 'boolean',
        'is_published' => 'boolean',
        'sort_order' => 'integer',
        'is_required' => 'boolean',
        'completion_threshold' => 'integer',
        'video_size' => 'integer',
    ];

    public const TYPES = ['video', 'text', 'document', 'resource', 'quiz', 'assignment', 'external'];

    protected $hidden = [
        'video_path',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function section()
    {
        return $this->belongsTo(CourseSection::class, 'course_section_id');
    }

    public function resources()
    {
        return $this->hasMany(LessonResource::class)->orderBy('sort_order')->orderBy('id');
    }

    public function quiz()
    {
        return $this->hasOne(Quiz::class);
    }

    public function progress()
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function isVideo(): bool
    {
        return $this->type === 'video';
    }

    public function isQuiz(): bool
    {
        return $this->type === 'quiz';
    }

    public function isText(): bool
    {
        return $this->type === 'text';
    }
}
