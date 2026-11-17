<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Course;
use App\Services\CertificateService;
use App\Services\CourseAccessService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly CertificateService $certificates
    ) {
    }

    /**
     * Issue a certificate for a completed course.
     */
    public function issue(Request $request, $slug)
    {
        $course = Course::query()->where('slug', $slug)->first();

        if (! $course) {
            return $this->error('Course not found', 404);
        }

        $user = $request->user();

        if (! $this->access->hasActiveAccess($user, $course)
            && ! $this->access->progress($user, $course)['is_complete']) {
            return $this->error('You need active access to this course first');
        }

        if (! $this->access->canIssueCertificate($user, $course)) {
            return $this->error('Complete all published lessons before requesting a certificate');
        }

        $certificate = $this->certificates->issue($user, $course);

        return $this->success('Certificate issued successfully', $this->format($certificate));
    }

    public function mine(Request $request)
    {
        $certificates = Certificate::query()
            ->with(['course:id,title,slug,thumbnail'])
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->get()
            ->map(fn (Certificate $certificate) => $this->format($certificate))
            ->values()
            ->all();

        return $this->success('Certificates retrieved successfully', $certificates);
    }

    public function show(Request $request, $number)
    {
        $certificate = Certificate::query()
            ->with(['course:id,title,slug,thumbnail', 'user:id,name'])
            ->where('certificate_number', $number)
            ->first();

        if (! $certificate) {
            return $this->error('Certificate not found', 404);
        }

        if ($certificate->user_id !== $request->user()->id) {
            return $this->error('Certificate not found', 404);
        }

        return $this->success('Certificate retrieved successfully', $this->format($certificate));
    }

    /**
     * Public verification endpoint (no authentication required).
     */
    public function verify($number)
    {
        $certificate = Certificate::query()
            ->with(['course:id,title,slug', 'user:id,name'])
            ->where('certificate_number', $number)
            ->first();

        if (! $certificate) {
            return $this->success('Certificate verification result', [
                'valid' => false,
                'message' => 'No certificate found with this number.',
            ]);
        }

        return $this->success('Certificate verification result', [
            'valid' => $certificate->isIssued(),
            'status' => $certificate->status,
            'certificate_number' => $certificate->certificate_number,
            'student_name' => $certificate->metadata['student_name'] ?? $certificate->user?->name,
            'course_title' => $certificate->metadata['course_title'] ?? $certificate->course?->title,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'revoked_at' => $certificate->revoked_at?->toIso8601String(),
        ]);
    }

    /**
     * Download the certificate as a PDF.
     */
    public function download(Request $request, $number)
    {
        $certificate = Certificate::query()
            ->with(['course:id,title,slug', 'user:id,name'])
            ->where('certificate_number', $number)
            ->first();

        if (! $certificate || $certificate->user_id !== $request->user()->id) {
            return $this->error('Certificate not found', 404);
        }

        if (! $certificate->isIssued()) {
            return $this->error('This certificate is no longer valid');
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->loadHtml(view('certificates.template', [
            'certificate' => $certificate,
            'studentName' => $certificate->metadata['student_name'] ?? $certificate->user?->name,
            'courseTitle' => $certificate->metadata['course_title'] ?? $certificate->course?->title,
            'provider' => config('courses.certificate.provider', 'Coaching Center'),
            'signature' => config('courses.certificate.signature', 'Director'),
            'issuedAt' => $certificate->issued_at?->format('F j, Y'),
        ])->render());
        $dompdf->render();

        $filename = 'certificate-'.$certificate->certificate_number.'.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    */

    public function adminIndex(Request $request)
    {
        $query = Certificate::query()->with(['user:id,name,email', 'course:id,title,slug']);

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->course_id);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $certificates = $query->latest('id')->paginate($perPage)->withQueryString();

        $certificates->setCollection(
            $certificates->getCollection()->map(fn (Certificate $certificate) => $this->format($certificate, true))
        );

        return $this->success('Certificates retrieved successfully', $certificates);
    }

    public function revoke($id)
    {
        $certificate = Certificate::find($id);

        if (! $certificate) {
            return $this->error('Certificate not found', 404);
        }

        $certificate->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
        ])->save();

        return $this->success('Certificate revoked successfully', $this->format($certificate->fresh()));
    }

    public function restore($id)
    {
        $certificate = Certificate::find($id);

        if (! $certificate) {
            return $this->error('Certificate not found', 404);
        }

        $certificate->forceFill([
            'status' => 'issued',
            'revoked_at' => null,
        ])->save();

        return $this->success('Certificate restored successfully', $this->format($certificate->fresh()));
    }

    private function format(Certificate $certificate, bool $includeUser = false): array
    {
        $data = [
            'id' => $certificate->id,
            'certificate_number' => $certificate->certificate_number,
            'status' => $certificate->status,
            'is_valid' => $certificate->isIssued(),
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'revoked_at' => $certificate->revoked_at?->toIso8601String(),
            'metadata' => $certificate->metadata,
            'course' => $certificate->relationLoaded('course') && $certificate->course ? [
                'id' => $certificate->course->id,
                'title' => $certificate->course->title,
                'slug' => $certificate->course->slug,
            ] : null,
            'download_url' => $certificate->isIssued()
                ? url('/api/certificates/'.$certificate->certificate_number.'/download')
                : null,
        ];

        if ($includeUser) {
            $data['user'] = $certificate->relationLoaded('user') && $certificate->user ? [
                'id' => $certificate->user->id,
                'name' => $certificate->user->name,
                'email' => $certificate->user->email,
            ] : null;
        }

        return $data;
    }
}
