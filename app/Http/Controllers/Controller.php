<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /** Admins manage everything; instructors only the courses they own. */
    protected function canManageCourse(?\App\Models\Course $course): bool
    {
        $user = request()->user();

        if (! $user || ! $course) {
            return false;
        }

        return $user->isAdmin() || ($user->isInstructor() && (int) $course->instructor_id === $user->id);
    }

    protected function forbidden()
    {
        return response()->json(['status' => false, 'message' => 'You are not allowed to manage this course', 'data' => []], 403);
    }

    private function denyFor(?\App\Models\Course $course)
    {
        // Unknown records fall through so the action returns its own 404/validation error.
        return ($course && ! $this->canManageCourse($course)) ? $this->forbidden() : null;
    }

    protected function denyCourse($id)
    {
        return $id ? $this->denyFor(\App\Models\Course::find($id)) : null;
    }

    protected function denySection($id)
    {
        return $id ? $this->denyFor(\App\Models\CourseSection::find($id)?->course) : null;
    }

    protected function denyLesson($id)
    {
        return $id ? $this->denyFor(\App\Models\Lesson::find($id)?->course) : null;
    }

    protected function denyQuiz($id)
    {
        return $id ? $this->denyFor(\App\Models\Quiz::find($id)?->course) : null;
    }

    protected function denyQuestion($id)
    {
        return $id ? $this->denyFor(\App\Models\QuizQuestion::find($id)?->quiz?->course) : null;
    }

    public function success($message = null, $data = []) {
        return response()->json([

            'status'    => true,
            'message'   => $message,
            'data'      => $data

        ]);
    }

    public function error($message = null, $data = []) {
        return response()->json([

            'status'    => false,
            'message'   => $message,
            'data'      => $data

        ]);
    }
}
