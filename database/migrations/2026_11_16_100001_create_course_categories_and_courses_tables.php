<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->boolean('status')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_category_id')->nullable()->constrained('course_categories')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('subtitle')->nullable();
            $table->longText('description')->nullable();
            $table->string('thumbnail')->nullable();
            $table->text('promo_video_url')->nullable();
            $table->string('level')->default('all');
            $table->string('language')->default('en');
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('offer_price', 10, 2)->nullable();
            // null = use config default, 0 = lifetime access
            $table->unsignedInteger('access_days')->nullable();
            $table->string('status')->default('draft')->index();
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->json('requirements')->nullable();
            $table->json('outcomes')->nullable();
            $table->unsignedInteger('total_lessons')->default(0);
            $table->unsignedInteger('total_students')->default(0);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
        Schema::dropIfExists('course_categories');
    }
};
