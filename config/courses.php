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

    'enroll_on_order_placement' => (bool) env('COURSE_ENROLL_ON_ORDER_PLACEMENT', true),

    /*
    |--------------------------------------------------------------------------
    | Video uploads
    |--------------------------------------------------------------------------
    */

    'video_disk' => env('COURSE_VIDEO_DISK', 'local'),
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

];
