<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartCourse;
use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CouponController extends Controller
{
    public function __construct(private readonly CouponService $coupons)
    {
    }

    /**
     * Preview a coupon against the student's selected cart. The final discount is always
     * recomputed server-side when the order is placed.
     */
    public function verifyCoupon(Request $request)
    {
        $request->validate(['code' => 'required|string|max:100']);

        $coupon = $this->coupons->find($request->code);

        if (! $coupon) {
            return $this->error('Invalid coupon code');
        }

        $user = $request->user();

        $courseLines = CartCourse::with('course')
            ->where('user_id', $user->id)
            ->where('is_selected', true)
            ->get()
            ->filter(fn ($row) => $row->course)
            ->map(fn ($row) => [
                'course_id' => $row->course_id,
                'category_id' => $row->course->course_category_id,
                'amount' => (float) $row->course->effective_price,
            ]);

        $productsSubtotal = (float) Cart::where('user_id', $user->id)
            ->where('is_selected', true)
            ->get()
            ->sum(fn ($item) => $item->price * $item->quantity);

        try {
            $result = $this->coupons->evaluate($coupon, $user, $courseLines, $productsSubtotal);
        } catch (ValidationException $e) {
            return $this->error($e->validator->errors()->first());
        }

        return $this->success('Coupon code is valid', [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'type' => $coupon->type,
            'discount' => $coupon->discount,
            'applies_to' => $coupon->applies_to,
            'discount_amount' => $result['discount'],
        ]);
    }

    public function getCoupons()
    {
        return $this->success('Coupons retrieved successfully', Coupon::with(['courses:id,title', 'courseCategories:id,name'])->latest('id')->get());
    }

    private function rules(?int $ignoreId = null): array
    {
        return [
            'code' => ['required', 'string', 'max:100', Rule::unique('coupons', 'code')->ignore($ignoreId)],
            'type' => 'required|in:fixed,percentage',
            'discount' => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at',
            'max_discount' => 'nullable|numeric|min:0',
            'min_purchase' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'per_user_limit' => 'nullable|integer|min:1',
            'applies_to' => 'nullable|in:all,products,courses',
            'course_ids' => 'nullable|array',
            'course_ids.*' => 'exists:courses,id',
            'course_category_ids' => 'nullable|array',
            'course_category_ids.*' => 'exists:course_categories,id',
        ];
    }

    private function fill(Coupon $coupon, Request $request): Coupon
    {
        $request->validate(array_merge($this->rules($coupon->exists ? $coupon->id : null), [
            'discount' => ['required', 'numeric', 'min:0', $request->type === 'percentage' ? 'max:100' : 'max:99999999'],
        ]));

        $coupon->fill($request->only([
            'code', 'type', 'discount', 'starts_at', 'expires_at', 'max_discount',
            'min_purchase', 'usage_limit', 'per_user_limit',
        ]));
        $coupon->is_active = $request->has('is_active') ? $request->boolean('is_active') : ($coupon->is_active ?? true);
        $coupon->applies_to = $request->input('applies_to', $coupon->applies_to ?? 'all');
        $coupon->save();

        if ($request->has('course_ids')) {
            $coupon->courses()->sync($request->course_ids ?? []);
        }

        if ($request->has('course_category_ids')) {
            $coupon->courseCategories()->sync($request->course_category_ids ?? []);
        }

        return $coupon->load(['courses:id,title', 'courseCategories:id,name']);
    }

    public function addCoupon(Request $request)
    {
        return $this->success('Coupon created successfully', $this->fill(new Coupon, $request));
    }

    public function updateCoupon(Request $request)
    {
        $coupon = Coupon::find($request->id);

        if (! $coupon) {
            return $this->error('Coupon not found');
        }

        return $this->success('Coupon updated successfully', $this->fill($coupon, $request));
    }

    public function deleteCoupon(Request $request, $id)
    {
        $coupon = Coupon::find($id);

        if (! $coupon) {
            return $this->error('Coupon not found', 404);
        }

        $coupon->delete();

        return $this->success('Coupon deleted successfully', null);
    }
}
