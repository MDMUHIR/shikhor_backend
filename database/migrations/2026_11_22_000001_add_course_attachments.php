<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Course-level attachments (PDF, slides, images...) reuse lesson_resources with lesson_id = null. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_resources', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->after('id')->constrained('courses')->cascadeOnDelete();
        });

        Schema::table('lesson_resources', function (Blueprint $table) {
            $table->unsignedBigInteger('lesson_id')->nullable()->change();
        });

        DB::table('lesson_resources')->whereNull('course_id')->update([
            'course_id' => DB::raw('(select course_id from lessons where lessons.id = lesson_resources.lesson_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('lesson_resources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_id');
        });
    }
};
