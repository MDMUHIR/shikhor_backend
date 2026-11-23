<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tables = ['users', 'courses', 'course_sections', 'lessons', 'quizzes', 'enrollments', 'course_reviews', 'certificates', 'products', 'orders', 'payments', 'exams', 'exam_questions', 'lesson_resources', 'live_sessions', 'course_posts', 'notifications', 'delivery_charges', 'coupons'];

foreach ($tables as $table) {
    try {
        echo $table.'='.Illuminate\Support\Facades\DB::table($table)->count().PHP_EOL;
    } catch (Throwable $e) {
        echo $table.'=ERR'.PHP_EOL;
    }
}

echo 'user_types='.json_encode(Illuminate\Support\Facades\DB::table('users')->select('type')->distinct()->pluck('type')).PHP_EOL;
echo 'enrollment_statuses='.json_encode(Illuminate\Support\Facades\DB::table('enrollments')->select('status')->distinct()->pluck('status')).PHP_EOL;
echo 'review_statuses='.json_encode(Illuminate\Support\Facades\DB::table('course_reviews')->select('status')->distinct()->pluck('status')).PHP_EOL;
