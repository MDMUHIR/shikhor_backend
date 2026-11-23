<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use SoftDeletes;

    protected $fillable = ['commentable_type', 'commentable_id', 'user_id', 'parent_id', 'body', 'is_pinned', 'likes_count'];

    protected $casts = ['is_pinned' => 'boolean', 'likes_count' => 'integer'];

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function replies()
    {
        return $this->hasMany(Comment::class, 'parent_id')->orderBy('id');
    }
}
