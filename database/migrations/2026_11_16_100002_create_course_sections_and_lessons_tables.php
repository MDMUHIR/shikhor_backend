<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('course_section_id')->nullable()->constrained('course_sections')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
            // video, text or quiz
            $table->string('type')->default('video');
            // external or upload (null when not a video lesson)
            $table->string('video_source')->nullable();
            $table->text('video_url')->nullable();
            $table->string('video_path')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->longText('content')->nullable();
            $table->boolean('is_preview')->default(false);
            $table->boolean('is_published')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('course_sections');
    }
};
