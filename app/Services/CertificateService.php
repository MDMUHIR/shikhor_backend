<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CertificateService
{
    /**
     * Issue (or return the existing) certificate for a completed course.
     * Safe against duplicates: (user_id, course_id) is unique in the database.
     */
    public function issue(User $user, Course $course): Certificate
    {
        $existing = $this->find($user, $course);

        if ($existing) {
            if ($existing->status === 'revoked') {
                // Revoked certificates are only restored by an admin (see CertificateController::restore).
                return $existing;
            }

            return $existing;
        }

        $enrollment = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        try {
            return Certificate::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'enrollment_id' => $enrollment?->id,
                'certificate_number' => $this->uniqueNumber(),
                'verification_token' => Str::random(40),
                'status' => 'issued',
                'issued_at' => Carbon::now(),
                'completed_at' => $enrollment?->completed_at ?? Carbon::now(),
                'metadata' => [
                    'student_name' => $user->name,
                    'course_title' => $course->title,
                ],
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->find($user, $course);
        }
    }

    private function find(User $user, Course $course): ?Certificate
    {
        return Certificate::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();
    }

    private function uniqueNumber(): string
    {
        do {
            $number = 'CERT-'.date('Y').'-'.strtoupper(Str::random(10));
        } while (Certificate::where('certificate_number', $number)->exists());

        return $number;
    }
}
