<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_number',
        'status',
        'payment_method',
        'payment_status',
        'name',
        'email',
        'phone',
        'phone_alt',
        'line1',
        'line2',
        'district',
        'city',
        'country',
        'currency',
        'subtotal',
        'delivery_charge',
        'discount',
        'discount_type',
        'coupon_code',
        'shipping_method',
        'estimated_delivery_days',
        'total_items',
        'total_quantity',
        'total',
        'notes',
        'paid_at',
        'refunded_amount',
        'idempotency_key',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'refunded_amount' => 'decimal:2',
    ];

    public function products()
    {
        return $this->belongsToMany(Product::class, 'order_products', 'order_id', 'product_id')
            ->withPivot('quantity', 'price', 'variant_id', 'attributes')
            ->with('images');
    }

    public function items()
    {
        return $this->hasMany(OrderProduct::class, 'order_id');
    }

    public function courseItems()
    {
        return $this->hasMany(OrderCourse::class, 'order_id');
    }

    public function courses()
    {
        return $this->belongsToMany(Course::class, 'order_courses', 'order_id', 'course_id')
            ->withPivot('name', 'slug', 'image', 'original_price', 'unit_price', 'discount', 'line_total', 'access_days');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
