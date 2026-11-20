<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive LMS upgrade: new columns/tables only. Existing data is preserved;
 * the only data statements are backfills of new columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->string('bio', 1000)->nullable();
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->foreignId('instructor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('short_description', 500)->nullable();
            $table->string('banner')->nullable();
            $table->string('promo_video_source')->nullable(); // upload|youtube|external
            $table->string('promo_video_path')->nullable();
            $table->timestamp('discount_starts_at')->nullable();
            $table->timestamp('discount_ends_at')->nullable();
            $table->unsignedInteger('estimated_completion_minutes')->nullable();
            $table->json('target_audience')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('certificate_enabled')->default(true);
            $table->unsignedTinyInteger('completion_percentage_required')->default(100);
            $table->boolean('require_quizzes_passed')->default(true);
            $table->unsignedInteger('total_sections')->default(0);
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->json('seo_meta')->nullable();
            $table->index(['status', 'published_at']);
            $table->index('course_category_id');
            $table->index('is_featured');
        });

        Schema::table('course_sections', function (Blueprint $table) {
            $table->boolean('is_published')->default(true);
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->string('video_external_id')->nullable();
            $table->string('video_thumbnail')->nullable();
            $table->string('video_mime')->nullable();
            $table->unsignedBigInteger('video_size')->nullable();
            $table->string('video_status')->nullable(); // ready|processing|failed
            $table->unsignedTinyInteger('completion_threshold')->nullable(); // percent watched
            $table->boolean('is_required')->default(true);
            $table->index(['course_id', 'sort_order']);
            $table->index('course_section_id');
        });

        Schema::create('lesson_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->string('title');
            $table->string('disk')->default('local');
            $table->string('path')->nullable();
            $table->string('external_url', 2048)->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('is_downloadable')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable();
            $table->decimal('progress_percentage', 5, 2)->default(0);
            $table->foreignId('last_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->timestamp('last_accessed_at')->nullable();
            $table->boolean('certificate_eligible')->default(false);
            $table->index(['course_id', 'status']);
        });
        DB::table('enrollments')->where('status', 'revoked')->update(['status' => 'cancelled']);

        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->unsignedInteger('max_position_seconds')->default(0);
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->index(['user_id', 'course_id']);
        });

        Schema::table('course_reviews', function (Blueprint $table) {
            $table->string('status')->default('pending');
            $table->unsignedInteger('helpful_count')->default(0);
            $table->index(['course_id', 'status']);
        });
        DB::table('course_reviews')->where('is_approved', true)->update(['status' => 'approved']);
        DB::table('course_reviews')->where('is_approved', false)->update(['status' => 'rejected']);

        Schema::table('certificates', function (Blueprint $table) {
            $table->string('verification_token', 64)->nullable()->unique();
            $table->timestamp('completed_at')->nullable();
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_options')->default(false);
            $table->boolean('is_required')->default(true);
        });
        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->text('explanation')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable();
            $table->decimal('refunded_amount', 10, 2)->default(0);
            $table->string('idempotency_key', 100)->nullable();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
            $table->index('payment_status');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->decimal('min_purchase', 10, 2)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->string('applies_to')->default('all'); // all|products|courses
        });

        Schema::create('coupon_courses', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->primary(['coupon_id', 'course_id']);
        });

        Schema::create('coupon_course_categories', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('course_category_id')->constrained('course_categories')->cascadeOnDelete();
            $table->primary(['coupon_id', 'course_category_id']);
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique('order_id');
            $table->index(['coupon_id', 'user_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_reference')->nullable(); // gateway session / payment id
            $table->string('transaction_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->decimal('refunded_amount', 10, 2)->default(0);
            $table->string('currency', 10);
            $table->string('status')->default('pending'); // pending|processing|successful|failed|cancelled|refunded
            $table->string('failure_reason')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
            $table->index(['order_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index('transaction_id');
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('event_id');
            $table->string('type')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('reason')->nullable();
            $table->string('status')->default('completed'); // completed|failed
            $table->string('provider_refund_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('coupon_course_categories');
        Schema::dropIfExists('coupon_courses');
        Schema::dropIfExists('lesson_resources');
    }
};
