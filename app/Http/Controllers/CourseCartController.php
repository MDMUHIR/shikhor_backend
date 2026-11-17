<?php

namespace App\Http\Controllers;

use App\Models\CartCourse;
use App\Models\Course;
use App\Models\Enrollment;
use Illuminate\Http\Request;

class CourseCartController extends Controller
{
    public function index(Request $request)
    {
        $items = CartCourse::query()
            ->with('course.category')
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->get();

        return $this->success('Get course cart', [
            'items' => $items->map(fn (CartCourse $item) => $this->formatItem($item))->values()->all(),
            'subtotal' => round($items->where('is_selected', true)->sum(
                fn (CartCourse $item) => (float) ($item->course?->effective_price ?? $item->price)
            ), 2),
            'count' => $items->count(),
            'selected_count' => $items->where('is_selected', true)->count(),
        ]);
    }

    public function add(Request $request)
    {
        $request->validate([
            'course_id' => 'required_without:course_ids|integer|exists:courses,id',
            'course_ids' => 'required_without:course_id|array',
            'course_ids.*' => 'integer|exists:courses,id',
        ]);

        $user = $request->user();
        $ids = $request->course_ids ?? [$request->course_id];
        $added = [];
        $skipped = [];

        foreach ($ids as $id) {
            $course = Course::query()->published()->find($id);

            if (! $course) {
                $skipped[] = ['course_id' => $id, 'reason' => 'Course is not available'];
                continue;
            }

            $enrolled = Enrollment::query()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->active()
                ->exists();

            if ($enrolled) {
                $skipped[] = ['course_id' => $id, 'reason' => 'You already have access to this course'];
                continue;
            }

            $item = CartCourse::updateOrCreate(
                ['user_id' => $user->id, 'course_id' => $course->id],
                ['price' => $course->effective_price, 'is_selected' => true]
            );

            $added[] = $this->formatItem($item->load('course.category'));
        }

        return $this->success('Course cart updated', [
            'added' => $added,
            'skipped' => $skipped,
        ]);
    }

    public function toggleSelection(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:cart_courses,id',
        ]);

        $item = CartCourse::query()
            ->where('id', $request->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $item) {
            return $this->error('Cart item not found', 404);
        }

        $item->forceFill(['is_selected' => ! $item->is_selected])->save();

        return $this->success('Cart item updated', $this->formatItem($item->fresh(['course.category'])));
    }

    public function selectAll(Request $request)
    {
        CartCourse::query()
            ->where('user_id', $request->user()->id)
            ->update(['is_selected' => true]);

        return $this->success('All courses selected', null);
    }

    public function deselectAll(Request $request)
    {
        CartCourse::query()
            ->where('user_id', $request->user()->id)
            ->update(['is_selected' => false]);

        return $this->success('All courses deselected', null);
    }

    public function destroy(Request $request, $id)
    {
        $item = CartCourse::query()
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $item) {
            return $this->error('Cart item not found', 404);
        }

        $item->delete();

        return $this->success('Course removed from cart', null);
    }

    private function formatItem(CartCourse $item): array
    {
        $course = $item->course;

        return [
            'id' => $item->id,
            'course_id' => $item->course_id,
            'price' => (float) ($course?->effective_price ?? $item->price),
            'is_selected' => $item->is_selected,
            'course' => $course ? [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
                'thumbnail' => $course->thumbnail,
                'price' => (float) $course->price,
                'offer_price' => $course->offer_price !== null ? (float) $course->offer_price : null,
                'effective_price' => $course->effective_price,
                'total_lessons' => $course->total_lessons,
                'duration_minutes' => $course->duration_minutes,
                'access_days' => $course->access_days,
                'rating' => [
                    'average' => (float) $course->rating_avg,
                    'count' => (int) $course->rating_count,
                ],
                'category' => $course->relationLoaded('category') && $course->category ? [
                    'id' => $course->category->id,
                    'name' => $course->category->name,
                    'slug' => $course->category->slug,
                ] : null,
            ] : null,
        ];
    }
}
