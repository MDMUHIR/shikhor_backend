<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->boolean('is_selected')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
        });

        Schema::create('order_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('image')->nullable();
            $table->decimal('original_price', 10, 2)->default(0);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('line_total', 10, 2)->default(0);
            // null = course/config default, 0 = lifetime
            $table->unsignedInteger('access_days')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_courses');
        Schema::dropIfExists('cart_courses');
    }
};
