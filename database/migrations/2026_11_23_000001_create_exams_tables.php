<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            // null = standalone exam open to any signed-in student (admin only); set = enrolled students of that course
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->unsignedInteger('duration_minutes')->default(30);
            $table->unsignedTinyInteger('pass_percentage')->default(50);
            $table->unsignedInteger('max_attempts')->nullable();

            // Negative marking (decided by the admin per exam)
            $table->boolean('negative_marking')->default(false);
            $table->string('negative_marking_type')->default('fraction'); // fraction of question marks | fixed marks
            $table->decimal('negative_marking_value', 6, 2)->default(0);
            $table->boolean('allow_negative_total')->default(false);

            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_options')->default(false);
            $table->string('show_answers')->default('after_submit'); // after_submit | after_exam_end | never
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index(['is_published', 'starts_at']);
            $table->index('course_id');
        });

        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->string('type')->default('single_choice'); // single_choice | multiple_choice | true_false
            $table->text('question');
            $table->json('options');
            $table->json('correct_answers');
            $table->decimal('marks', 6, 2)->default(1);
            $table->decimal('negative_marks', 6, 2)->nullable(); // overrides the exam rule for this question
            $table->text('explanation')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['exam_id', 'sort_order']);
        });

        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // "{exam}:{user}" while in progress, NULL afterwards -> unique index = one live attempt per student
            $table->string('active_key')->nullable()->unique();
            $table->string('status')->default('in_progress'); // in_progress | submitted | expired
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->json('answers')->nullable();
            $table->json('question_order')->nullable();
            $table->json('settings')->nullable(); // snapshot of marking rules at start
            $table->decimal('score', 8, 2)->default(0);
            $table->decimal('total_marks', 8, 2)->default(0);
            $table->decimal('percentage', 6, 2)->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->unsignedInteger('wrong_count')->default(0);
            $table->unsignedInteger('unanswered_count')->default(0);
            $table->decimal('negative_marks_total', 8, 2)->default(0);
            $table->boolean('passed')->default(false);
            $table->timestamps();

            $table->index(['exam_id', 'user_id']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempts');
        Schema::dropIfExists('exam_questions');
        Schema::dropIfExists('exams');
    }
};
