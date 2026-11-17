<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CertificateService
{
    /**
     * Issue (or return the existing) certificate for a completed course.
     */
    public function issue(User $user, Course $course): Certificate
    {
        $existing = Certificate::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if ($existing) {
            if ($existing->status === 'revoked') {
                $existing->forceFill([
                    'status' => 'issued',
                    'revoked_at' => null,
                    'issued_at' => $existing->issued_at ?? Carbon::now(),
                ])->save();
            }

            return $existing;
        }

        $enrollment = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        return Certificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'enrollment_id' => $enrollment?->id,
            'certificate_number' => $this->uniqueNumber($course, $user),
            'status' => 'issued',
            'issued_at' => Carbon::now(),
            'metadata' => [
                'student_name' => $user->name,
                'course_title' => $course->title,
            ],
        ]);
    }

    private function uniqueNumber(Course $course, User $user): string
    {
        do {
            $number = sprintf(
                'CERT-%s-%05d-%05d',
                strtoupper(Str::random(6)),
                $course->id,
                $user->id
            );
        } while (Certificate::where('certificate_number', $number)->exists());

        return $number;
    }
}
