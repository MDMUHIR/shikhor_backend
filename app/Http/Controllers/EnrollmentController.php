<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\CourseAccessService;
use App\Services\CourseFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EnrollmentController extends Controller
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly CourseFormatter $formatter
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Student
    |--------------------------------------------------------------------------
    */

    public function myCourses(Request $request)
    {
        $user = $request->user();

        $enrollments = Enrollment::query()
            ->with(['course.category'])
            ->where('user_id', $user->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
            ->latest('id')
            ->get();

        $data = $enrollments->map(function (Enrollment $enrollment) use ($user) {
            $course = $enrollment->course;

            if (! $course) {
                return null;
            }

            $progress = in_array($enrollment->status, ['active', 'completed'], true)
                ? $this->access->progress($user, $course)
                : null;

            return [
                'enrollment' => [
                    'id' => $enrollment->id,
                    'status' => $enrollment->status,
                    'is_active' => $enrollment->isActive(),
                    'source' => $enrollment->source,
                    'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
                    'expires_at' => $enrollment->expires_at?->toIso8601String(),
                    'remaining_days' => $enrollment->remainingDays(),
                    'is_lifetime' => $enrollment->isLifetime(),
                ],
                'course' => $this->formatter->formatCourse($course),
                'progress' => $progress,
            ];
        })->filter()->values()->all();

        return $this->success('Enrolled courses retrieved successfully', $data);
    }

    public function show(Request $request, $slug)
    {
        $user = $request->user();

        $course = Course::query()->where('slug', $slug)->first();

        if (! $course) {
            return $this->error('Course not found', 404);
        }

        $enrollment = $this->access->enrollment($user, $course);

        if (! $enrollment) {
            return $this->error('You are not enrolled in this course');
        }

        $certificate = Certificate::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        return $this->success('Enrollment retrieved successfully', [
            'id' => $enrollment->id,
            'status' => $enrollment->status,
            'is_active' => $enrollment->isActive(),
            'is_lifetime' => $enrollment->isLifetime(),
            'source' => $enrollment->source,
            'order_id' => $enrollment->order_id,
            'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
            'starts_at' => $enrollment->starts_at?->toIso8601String(),
            'expires_at' => $enrollment->expires_at?->toIso8601String(),
            'remaining_days' => $enrollment->remainingDays(),
            'course' => $this->formatter->formatCourse($course->loadMissing('category')),
            'progress' => $this->access->progress($user, $course),
            'certificate' => $certificate ? [
                'id' => $certificate->id,
                'certificate_number' => $certificate->certificate_number,
                'status' => $certificate->status,
                'issued_at' => $certificate->issued_at?->toIso8601String(),
            ] : null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    */

    public function adminIndex(Request $request)
    {
        $query = Enrollment::query()->with(['user:id,name,email', 'course:id,title,slug']);

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->course_id);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $term = '%'.$request->search.'%';
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $enrollments = $query->latest('id')->paginate($perPage)->withQueryString();

        $enrollments->setCollection(
            $enrollments->getCollection()->map(fn (Enrollment $enrollment) => [
                'id' => $enrollment->id,
                'user' => $enrollment->user ? [
                    'id' => $enrollment->user->id,
                    'name' => $enrollment->user->name,
                    'email' => $enrollment->user->email,
                ] : null,
                'course' => $enrollment->course ? [
                    'id' => $enrollment->course->id,
                    'title' => $enrollment->course->title,
                    'slug' => $enrollment->course->slug,
                ] : null,
                'order_id' => $enrollment->order_id,
                'source' => $enrollment->source,
                'status' => $enrollment->status,
                'is_active' => $enrollment->isActive(),
                'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
                'expires_at' => $enrollment->expires_at?->toIso8601String(),
                'remaining_days' => $enrollment->remainingDays(),
            ])
        );

        return $this->success('Enrollments retrieved successfully', $enrollments);
    }

    public function grant(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'course_id' => 'required|exists:courses,id',
            'access_days' => 'nullable|integer|min:0',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $user = User::findOrFail($request->user_id);
        $course = Course::findOrFail($request->course_id);

        $expiresAt = $request->filled('expires_at')
            ? Carbon::parse($request->expires_at)
            : null;

        $enrollment = $this->access->enroll(
            $user,
            $course,
            null,
            $request->has('access_days') ? (int) $request->access_days : null,
            $expiresAt,
            'manual'
        );

        $user->notify(new \App\Notifications\LmsNotification(
            'course_enrolled',
            'You are enrolled',
            'You now have access to "'.$course->title.'".',
            ['course_id' => $course->id, 'slug' => $course->slug]
        ));

        return $this->success('Course access granted successfully', [
            'id' => $enrollment->id,
            'user_id' => $enrollment->user_id,
            'course_id' => $enrollment->course_id,
            'status' => $enrollment->status,
            'source' => $enrollment->source,
            'expires_at' => $enrollment->expires_at?->toIso8601String(),
        ]);
    }

    public function extend(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:enrollments,id',
            'days' => 'required|integer|min:1',
        ]);

        $enrollment = Enrollment::findOrFail($request->id);

        $base = $enrollment->expires_at !== null && $enrollment->expires_at->isFuture()
            ? $enrollment->expires_at->copy()
            : Carbon::now();

        $enrollment->forceFill([
            // Extending never revives a cancelled/suspended enrollment (use reactivate for that).
            'status' => $enrollment->status === 'expired' ? 'active' : $enrollment->status,
            'expires_at' => $base->addDays((int) $request->days),
        ])->save();

        return $this->success('Enrollment extended successfully', [
            'id' => $enrollment->id,
            'expires_at' => $enrollment->expires_at?->toIso8601String(),
            'remaining_days' => $enrollment->remainingDays(),
        ]);
    }

    public function revoke($id)
    {
        $enrollment = Enrollment::find($id);

        if (! $enrollment) {
            return $this->error('Enrollment not found', 404);
        }

        $enrollment->forceFill(['status' => 'cancelled'])->save();
        $enrollment->course?->refreshStudentCount();

        return $this->success('Enrollment revoked successfully', [
            'id' => $enrollment->id,
            'status' => $enrollment->status,
        ]);
    }

    public function reactivate($id)
    {
        $enrollment = Enrollment::find($id);

        if (! $enrollment) {
            return $this->error('Enrollment not found', 404);
        }

        if ($enrollment->expires_at !== null && $enrollment->expires_at->isPast()) {
            return $this->error('This enrollment has expired, extend it instead');
        }

        $enrollment->forceFill(['status' => $enrollment->completed_at ? 'completed' : 'active'])->save();
        $enrollment->course?->refreshStudentCount();

        return $this->success('Enrollment reactivated successfully', [
            'id' => $enrollment->id,
            'status' => $enrollment->status,
        ]);
    }
}
