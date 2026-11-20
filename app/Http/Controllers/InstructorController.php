<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\User;
use App\Services\CourseFormatter;
use Illuminate\Http\Request;

/** Public instructor directory and profiles. Only exposes public profile fields. */
class InstructorController extends Controller
{
    public function __construct(private readonly CourseFormatter $formatter)
    {
    }

    /** Every active instructor account (those with published courses first). */
    public function index(Request $request)
    {
        $instructors = User::query()
            ->where('type', User::ROLE_INSTRUCTOR)
            ->where('is_active', true)
            ->withCount(['taughtCourses as courses_count' => fn ($q) => $q->published()])
            ->withSum(['taughtCourses as students_count' => fn ($q) => $q->published()], 'total_students')
            ->orderByDesc('courses_count')
            ->orderByDesc('students_count')
            ->orderBy('name')
            ->get();

        return $this->success('Instructors retrieved successfully', $instructors->map(fn (User $u) => $this->format($u))->values());
    }

    public function show($id)
    {
        $instructor = User::query()
            ->where('type', User::ROLE_INSTRUCTOR)
            ->where('is_active', true)
            ->withCount(['taughtCourses as courses_count' => fn ($q) => $q->published()])
            ->withSum(['taughtCourses as students_count' => fn ($q) => $q->published()], 'total_students')
            ->find($id);

        if (! $instructor) {
            return $this->notFound('Instructor not found');
        }

        $courses = Course::query()
            ->published()
            ->with(['category', 'instructor:id,name,headline,bio,avatar,is_active'])
            ->where('instructor_id', $instructor->id)
            ->orderByDesc('total_students')
            ->get();

        return $this->success('Instructor retrieved successfully', $this->format($instructor) + [
            'courses' => $courses->map(fn (Course $course) => $this->formatter->formatCourse($course))->values(),
        ]);
    }

    private function format(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'headline' => $user->headline,
            'bio' => $user->bio,
            'avatar' => $user->avatar,
            'courses_count' => (int) ($user->courses_count ?? 0),
            'students_count' => (int) ($user->students_count ?? 0),
        ];
    }
}
