<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('provider')->default('zoom'); // zoom | meet | youtube | facebook | teams | other
            $table->string('join_url', 2048);
            $table->string('youtube_video_id', 20)->nullable(); // set => can be embedded next to the chat
            $table->timestamp('scheduled_at');
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->string('status')->default('scheduled'); // scheduled | live | ended | cancelled
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('recording_url', 2048)->nullable();
            $table->boolean('chat_enabled')->default(true);
            $table->boolean('chat_locked')->default(false);
            $table->timestamps();

            $table->index(['course_id', 'scheduled_at']);
            $table->index(['course_id', 'status']);
        });

        Schema::create('course_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('live_session_id')->nullable()->constrained('live_sessions')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('body');
            $table->string('link_url', 2048)->nullable();
            $table->string('link_label', 120)->nullable();
            $table->string('link_provider', 30)->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('pinned_at')->nullable();
            $table->boolean('comments_locked')->default(false);
            $table->timestamps();

            $table->index(['course_id', 'is_pinned', 'id']);
        });

        // Comments on announcements ("post") and the live chat of a class ("live").
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->string('commentable_type', 20);
            $table->unsignedBigInteger('commentable_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_pinned')->default(false);
            $table->unsignedInteger('likes_count')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['commentable_type', 'commentable_id', 'id']);
            $table->index('parent_id');
        });

        Schema::create('likes', function (Blueprint $table) {
            $table->id();
            $table->string('likeable_type', 20); // post | comment
            $table->unsignedBigInteger('likeable_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['likeable_type', 'likeable_id', 'user_id']);
        });

        Schema::create('community_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('muted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('until')->nullable(); // null = until unmuted
            $table->timestamps();

            $table->unique(['course_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_mutes');
        Schema::dropIfExists('likes');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('course_posts');
        Schema::dropIfExists('live_sessions');
    }
};
