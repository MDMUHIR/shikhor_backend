<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default course access duration
    |--------------------------------------------------------------------------
    |
    | Used when a course does not define its own access_days value. Set a
    | course's access_days to 0 for lifetime access.
    |
    */

    'default_access_days' => (int) env('COURSE_DEFAULT_ACCESS_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Enrollment timing
    |--------------------------------------------------------------------------
    |
    | When true, students are enrolled as soon as an order is placed (useful
    | while no payment gateway is connected). When false, enrollment happens
    | when an admin marks the order payment_status as paid.
    |
    */

    /*
    | Enrollment mode
    |--------------------------------------------------------------------------
    | manual: a paid/approved order does NOT grant access; an admin must enroll the
    |         student (POST admin/orders/{id}/enroll or admin/enrollments/grant).
    | auto:   access is granted as soon as the payment is verified.
    */
    'enrollment_mode' => env('COURSE_ENROLLMENT_MODE', 'manual'),

    'enroll_on_order_placement' => (bool) env('COURSE_ENROLL_ON_ORDER_PLACEMENT', false),

    /*
    |--------------------------------------------------------------------------
    | Video uploads
    |--------------------------------------------------------------------------
    */

    'video_disk' => env('COURSE_VIDEO_DISK', 'local'),
    // Disk for downloadable lesson files (keep private; use an s3 disk in production)
    'resource_disk' => env('COURSE_RESOURCE_DISK', 'local'),
    'video_max_size_kb' => (int) env('COURSE_VIDEO_MAX_SIZE_KB', 524288),

    /*
    |--------------------------------------------------------------------------
    | Signed streaming URLs
    |--------------------------------------------------------------------------
    |
    | Lifetime (in minutes) of the temporary signed URLs used to stream
    | privately stored lesson videos.
    |
    */

    'video_stream_url_ttl' => (int) env('COURSE_VIDEO_STREAM_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Certificates
    |--------------------------------------------------------------------------
    */

    'certificate' => [
        'provider' => env('COURSE_CERTIFICATE_PROVIDER', 'Coaching Center'),
        'signature' => env('COURSE_CERTIFICATE_SIGNATURE', 'Director'),
    ],

    'reviews' => [
        // false = new/edited reviews wait for admin moderation
        'auto_approve' => (bool) env('COURSE_REVIEWS_AUTO_APPROVE', true),
    ],

    'payments' => [
        // providers: manual (admin confirms), stripe
        'default_provider' => env('PAYMENT_PROVIDER', 'manual'),
        'webhook_tolerance_seconds' => (int) env('PAYMENT_WEBHOOK_TOLERANCE', 300),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    ],

    'certificate_completion_requires_quizzes' => true,

];
