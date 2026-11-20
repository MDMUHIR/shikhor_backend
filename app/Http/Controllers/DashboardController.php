<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\Enrollment;
use App\Models\LessonProgress;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** Student dashboard: enrolled courses, resume point, learning history, certificates. */
    public function student(Request $request)
    {
        $user = $request->user();

        $enrollments = Enrollment::query()
            ->with(['course:id,title,slug,thumbnail,total_lessons', 'lastLesson:id,title,course_id'])
            ->where('user_id', $user->id)
            ->latest('last_accessed_at')
            ->get();

        $activeIds = $enrollments->filter->isActive();

        $format = fn (Enrollment $e) => [
            'enrollment_id' => $e->id,
            'status' => $e->status,
            'is_active' => $e->isActive(),
            'progress_percentage' => (float) $e->progress_percentage,
            'expires_at' => $e->expires_at?->toIso8601String(),
            'completed_at' => $e->completed_at?->toIso8601String(),
            'certificate_eligible' => (bool) $e->certificate_eligible,
            'last_accessed_at' => $e->last_accessed_at?->toIso8601String(),
            'course' => $e->course ? [
                'id' => $e->course->id,
                'title' => $e->course->title,
                'slug' => $e->course->slug,
                'thumbnail' => $e->course->thumbnail,
                'total_lessons' => $e->course->total_lessons,
            ] : null,
            'resume_lesson' => $e->lastLesson ? ['id' => $e->lastLesson->id, 'title' => $e->lastLesson->title] : null,
        ];

        $history = LessonProgress::query()
            ->with('lesson:id,title,course_id')
            ->where('user_id', $user->id)
            ->whereNotNull('last_activity_at')
            ->latest('last_activity_at')
            ->limit(10)
            ->get()
            ->map(fn (LessonProgress $p) => [
                'lesson_id' => $p->lesson_id,
                'lesson_title' => $p->lesson?->title,
                'course_id' => $p->course_id,
                'completed' => $p->completed_at !== null,
                'last_position_seconds' => $p->last_position_seconds,
                'last_activity_at' => $p->last_activity_at?->toIso8601String(),
            ])->values();

        return $this->success('Dashboard retrieved successfully', [
            'stats' => [
                'enrolled_courses' => $enrollments->count(),
                'active_courses' => $activeIds->count(),
                'completed_courses' => $enrollments->whereNotNull('completed_at')->count(),
                'certificates' => Certificate::where('user_id', $user->id)->where('status', 'issued')->count(),
                'lessons_completed' => LessonProgress::where('user_id', $user->id)->whereNotNull('completed_at')->count(),
                'unread_notifications' => $user->unreadNotifications()->count(),
            ],
            'continue_learning' => $activeIds->whereNull('completed_at')->take(5)->map($format)->values(),
            'courses' => $enrollments->map($format)->values(),
            'recent_activity' => $history,
        ]);
    }

    /**
     * Admin / instructor analytics. Instructors only see their own courses.
     */
    public function analytics(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        $courseIds = Course::query()
            ->when(! $isAdmin, fn ($q) => $q->where('instructor_id', $user->id))
            ->pluck('id');

        $days = min(max((int) $request->query('days', 30), 1), 365);
        $since = now()->subDays($days);

        // Revenue is computed from paid order lines so it is correct per-course and net of nothing hidden.
        $lines = DB::table('order_courses')
            ->join('orders', 'orders.id', '=', 'order_courses.order_id')
            ->whereIn('order_courses.course_id', $courseIds)
            ->whereIn('orders.payment_status', ['paid', 'partially_refunded']);

        $revenueTotal = (float) (clone $lines)->sum('order_courses.line_total');
        $revenueRange = (float) (clone $lines)->where('orders.paid_at', '>=', $since)->sum('order_courses.line_total');

        $enrollmentsQuery = Enrollment::query()->whereIn('course_id', $courseIds);

        $topCourses = DB::table('order_courses')
            ->join('orders', 'orders.id', '=', 'order_courses.order_id')
            ->whereIn('order_courses.course_id', $courseIds)
            ->whereIn('orders.payment_status', ['paid', 'partially_refunded'])
            ->groupBy('order_courses.course_id', 'order_courses.name')
            ->orderByDesc(DB::raw('SUM(order_courses.line_total)'))
            ->limit(5)
            ->get([
                'order_courses.course_id',
                'order_courses.name',
                DB::raw('COUNT(*) as sales'),
                DB::raw('SUM(order_courses.line_total) as revenue'),
            ]);

        $daily = (clone $lines)
            ->where('orders.paid_at', '>=', $since)
            ->groupBy(DB::raw('DATE(orders.paid_at)'))
            ->orderBy(DB::raw('DATE(orders.paid_at)'))
            ->get([DB::raw('DATE(orders.paid_at) as date'), DB::raw('SUM(order_courses.line_total) as revenue')]);

        $totalEnrollments = (clone $enrollmentsQuery)->count();
        $completed = (clone $enrollmentsQuery)->whereNotNull('completed_at')->count();

        $data = [
            'range_days' => $days,
            'courses' => [
                'total' => $courseIds->count(),
                'published' => Course::whereIn('id', $courseIds)->where('status', 'published')->count(),
            ],
            'revenue' => [
                'total' => round($revenueTotal, 2),
                'range' => round($revenueRange, 2),
                'daily' => $daily,
            ],
            'enrollments' => [
                'total' => $totalEnrollments,
                'range' => (clone $enrollmentsQuery)->where('created_at', '>=', $since)->count(),
                'active' => (clone $enrollmentsQuery)->active()->count(),
                'completed' => $completed,
                'completion_rate' => $totalEnrollments > 0 ? round($completed / $totalEnrollments * 100, 2) : 0,
            ],
            'reviews' => [
                'average' => round((float) CourseReview::whereIn('course_id', $courseIds)->where('status', 'approved')->avg('rating'), 2),
                'pending' => CourseReview::whereIn('course_id', $courseIds)->where('status', 'pending')->count(),
            ],
            'certificates_issued' => Certificate::whereIn('course_id', $courseIds)->where('status', 'issued')->count(),
            'top_courses' => $topCourses,
        ];

        if ($isAdmin) {
            $data['orders'] = [
                'total' => Order::count(),
                'paid' => Order::where('payment_status', 'paid')->count(),
                'pending_payment' => Order::where('payment_status', 'pending')->count(),
            ];
            $data['payments'] = [
                'collected' => round((float) Payment::whereIn('status', ['successful', 'refunded'])->sum('amount'), 2),
                'refunded' => round((float) Payment::sum('refunded_amount'), 2),
                'failed' => Payment::where('status', 'failed')->count(),
            ];
            $data['students'] = User::where('type', 'customer')->count();
        }

        return $this->success('Analytics retrieved successfully', $data);
    }
}
