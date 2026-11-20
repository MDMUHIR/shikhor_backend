<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A downloadable file or link. lesson_id = null means a course-level attachment.
 */
class LessonResource extends Model
{
    protected $fillable = [
        'course_id', 'lesson_id', 'title', 'disk', 'path', 'external_url', 'mime_type', 'size', 'is_downloadable', 'sort_order',
    ];

    protected $casts = ['is_downloadable' => 'boolean', 'size' => 'integer', 'sort_order' => 'integer'];

    protected $hidden = ['path', 'disk'];

    protected $appends = ['kind'];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /** pdf | slides | image | document | spreadsheet | archive | link | file */
    public function getKindAttribute(): string
    {
        if ($this->external_url) {
            return 'link';
        }

        $ext = strtolower(pathinfo((string) $this->path, PATHINFO_EXTENSION));

        return match (true) {
            $ext === 'pdf' => 'pdf',
            in_array($ext, ['ppt', 'pptx'], true) => 'slides',
            in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) => 'image',
            in_array($ext, ['doc', 'docx', 'txt'], true) => 'document',
            in_array($ext, ['xls', 'xlsx', 'csv'], true) => 'spreadsheet',
            $ext === 'zip' => 'archive',
            default => 'file',
        };
    }
}
