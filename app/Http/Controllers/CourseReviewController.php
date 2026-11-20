<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseReview;
use App\Services\CourseAccessService;
use App\Services\CourseFormatter;
use Illuminate\Http\Request;

class CourseReviewController extends Controller
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly CourseFormatter $formatter
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Public
    |--------------------------------------------------------------------------
    */

    public function index(Request $request, $slug)
    {
        $course = Course::query()->published()->where('slug', $slug)->first();

        if (! $course) {
            return $this->error('Course not found', 404);
        }

        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);

        $reviews = $course->reviews()
            ->where('status', 'approved')
            ->with('user:id,name')
            ->when($request->filled('rating'), fn ($query) => $query->where('rating', (int) $request->rating))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        $reviews->setCollection(
            $reviews->getCollection()->map(fn (CourseReview $review) => $this->format($review))
        );

        return $this->success('Reviews retrieved successfully', [
            'summary' => $this->formatter->reviewSummary($course),
            'reviews' => $reviews,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Student
    |--------------------------------------------------------------------------
    */

    public function mine(Request $request, $slug)
    {
        $course = Course::query()->where('slug', $slug)->first();

        if (! $course) {
            return $this->error('Course not found', 404);
        }

        $review = CourseReview::query()
            ->where('course_id', $course->id)
            ->where('user_id', $request->user()->id)
            ->first();

        return $this->success(
            'Review retrieved successfully',
            $review ? $this->format($review) : null
        );
    }

    public function store(Request $request, $slug)
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'comment' => 'nullable|string|max:5000',
        ]);

        $course = Course::query()->published()->where('slug', $slug)->first();

        if (! $course) {
            return $this->error('Course not found', 404);
        }

        if (! $this->access->hasActiveAccess($request->user(), $course)) {
            return $this->error('Only enrolled students can review this course');
        }

        $status = config('courses.reviews.auto_approve', true) ? 'approved' : 'pending';

        $review = CourseReview::query()->updateOrCreate(
            [
                'course_id' => $course->id,
                'user_id' => $request->user()->id,
            ],
            [
                'rating' => (int) $request->rating,
                'title' => $request->title,
                'comment' => $request->comment,
                'status' => $status,
                'is_approved' => $status === 'approved',
            ]
        );

        $course->refreshRating();

        return $this->success('Review saved successfully', $this->format($review->load('user:id,name')));
    }

    public function destroy(Request $request, $slug)
    {
        $course = Course::query()->where('slug', $slug)->first();

        if (! $course) {
            return $this->error('Course not found', 404);
        }

        $review = CourseReview::query()
            ->where('course_id', $course->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $review) {
            return $this->error('Review not found', 404);
        }

        $review->delete();
        $course->refreshRating();

        return $this->success('Review deleted successfully', null);
    }

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    */

    public function adminIndex(Request $request)
    {
        $query = CourseReview::query()->with(['user:id,name,email', 'course:id,title,slug']);

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->course_id);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('is_approved')) {
            $query->where('status', $request->boolean('is_approved') ? 'approved' : 'rejected');
        }

        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->rating);
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $reviews = $query->latest('id')->paginate($perPage)->withQueryString();

        $reviews->setCollection(
            $reviews->getCollection()->map(fn (CourseReview $review) => $this->format($review, true))
        );

        return $this->success('Reviews retrieved successfully', $reviews);
    }

    public function approve($id)
    {
        $review = CourseReview::find($id);

        if (! $review) {
            return $this->error('Review not found', 404);
        }

        $review->forceFill(['status' => 'approved', 'is_approved' => true])->save();
        $review->course?->refreshRating();

        return $this->success('Review approved successfully', $this->format($review->fresh('user:id,name')));
    }

    public function reject($id)
    {
        $review = CourseReview::find($id);

        if (! $review) {
            return $this->error('Review not found', 404);
        }

        $review->forceFill(['status' => 'rejected', 'is_approved' => false])->save();
        $review->course?->refreshRating();

        return $this->success('Review rejected successfully', $this->format($review->fresh('user:id,name')));
    }

    public function adminDestroy($id)
    {
        $review = CourseReview::find($id);

        if (! $review) {
            return $this->error('Review not found', 404);
        }

        $course = $review->course;
        $review->delete();
        $course?->refreshRating();

        return $this->success('Review deleted successfully', null);
    }

    private function format(CourseReview $review, bool $includeCourse = false): array
    {
        $data = [
            'id' => $review->id,
            'course_id' => $review->course_id,
            'rating' => $review->rating,
            'title' => $review->title,
            'comment' => $review->comment,
            'status' => $review->status, 'is_approved' => $review->status === 'approved',
            'user' => $review->relationLoaded('user') && $review->user ? [
                'id' => $review->user->id,
                'name' => $review->user->name,
            ] : null,
            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),
        ];

        if ($includeCourse) {
            if (isset($data['user'])) {
                $data['user']['email'] = $review->user?->email;
            }

            $data['course'] = $review->relationLoaded('course') && $review->course ? [
                'id' => $review->course->id,
                'title' => $review->course->title,
                'slug' => $review->course->slug,
            ] : null;
        }

        return $data;
    }
}
