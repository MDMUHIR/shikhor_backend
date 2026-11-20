<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CouponService
{
    public function find(string $code): ?Coupon
    {
        return Coupon::query()->whereRaw('LOWER(code) = ?', [strtolower(trim($code))])->first();
    }

    /**
     * Validate a coupon and calculate the discount from SERVER-side prices.
     *
     * @param  Collection  $courseLines  items of ['course_id', 'category_id', 'amount']
     * @return array{discount: float, eligible_subtotal: float}
     *
     * @throws ValidationException
     */
    public function evaluate(Coupon $coupon, User $user, Collection $courseLines, float $productsSubtotal): array
    {
        if (! $coupon->is_active) {
            $this->fail('This coupon is no longer active');
        }

        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            $this->fail('This coupon is not active yet');
        }

        if ($coupon->expires_at && $coupon->expires_at->isPast()) {
            $this->fail('This coupon has expired');
        }

        if ($coupon->usage_limit !== null && $coupon->times_used >= $coupon->usage_limit) {
            $this->fail('This coupon has reached its usage limit');
        }

        if ($coupon->per_user_limit !== null) {
            $used = CouponUsage::query()
                ->where('coupon_id', $coupon->id)
                ->where('user_id', $user->id)
                ->whereNull('released_at')
                ->count();

            if ($used >= $coupon->per_user_limit) {
                $this->fail('You have already used this coupon the maximum number of times');
            }
        }

        $restrictedCourses = $coupon->courses()->pluck('courses.id');
        $restrictedCategories = $coupon->courseCategories()->pluck('course_categories.id');

        $eligibleCourses = $courseLines->filter(function ($line) use ($restrictedCourses, $restrictedCategories) {
            if ($restrictedCourses->isEmpty() && $restrictedCategories->isEmpty()) {
                return true;
            }

            return $restrictedCourses->contains($line['course_id'])
                || ($line['category_id'] && $restrictedCategories->contains($line['category_id']));
        })->sum('amount');

        $eligible = match ($coupon->applies_to) {
            'courses' => (float) $eligibleCourses,
            'products' => $productsSubtotal,
            default => (float) $eligibleCourses + $productsSubtotal,
        };

        if ($eligible <= 0) {
            $this->fail('This coupon does not apply to the items in your order');
        }

        if ($coupon->min_purchase !== null && $eligible < (float) $coupon->min_purchase) {
            $this->fail('A minimum purchase of '.$coupon->min_purchase.' is required for this coupon');
        }

        $discount = $coupon->type === 'percentage'
            ? $eligible * ((float) $coupon->discount / 100)
            : (float) $coupon->discount;

        if ($coupon->max_discount !== null) {
            $discount = min($discount, (float) $coupon->max_discount);
        }

        return [
            'discount' => round(min($discount, $eligible), 2),
            'eligible_subtotal' => round($eligible, 2),
        ];
    }

    /**
     * Record usage atomically. Call inside the order transaction.
     */
    public function redeem(Order $order, Coupon $coupon, float $discount): void
    {
        $locked = Coupon::query()->lockForUpdate()->find($coupon->id);

        if ($locked->usage_limit !== null && $locked->times_used >= $locked->usage_limit) {
            $this->fail('This coupon has reached its usage limit');
        }

        CouponUsage::create([
            'coupon_id' => $locked->id,
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'discount_amount' => $discount,
        ]);

        $locked->increment('times_used');
    }

    /** Give the coupon use back (order cancelled/refunded). */
    public function release(Order $order): void
    {
        $usage = CouponUsage::query()->where('order_id', $order->id)->whereNull('released_at')->first();

        if (! $usage) {
            return;
        }

        $usage->forceFill(['released_at' => now()])->save();
        Coupon::query()->where('id', $usage->coupon_id)->where('times_used', '>', 0)->decrement('times_used');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['coupon' => [$message]]);
    }
}
