<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommunityMute extends Model
{
    protected $fillable = ['course_id', 'user_id', 'muted_by', 'until'];

    protected $casts = ['until' => 'datetime'];

    public function isActive(): bool
    {
        return $this->until === null || $this->until->isFuture();
    }
}
