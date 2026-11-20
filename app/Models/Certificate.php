<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'enrollment_id',
        'certificate_number',
        'status',
        'issued_at',
        'revoked_at',
        'completed_at',
        'verification_token',
        'metadata',
    ];

    protected $hidden = ['verification_token'];

    protected $casts = [
        'issued_at' => 'datetime',
        'revoked_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued' && $this->revoked_at === null;
    }
}
