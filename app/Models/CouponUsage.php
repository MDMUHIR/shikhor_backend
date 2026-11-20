<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponUsage extends Model
{
    protected $fillable = ['coupon_id', 'user_id', 'order_id', 'discount_amount', 'released_at'];

    protected $casts = ['discount_amount' => 'decimal:2', 'released_at' => 'datetime'];

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }
}
