<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const SUCCESSFUL = 'successful';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const REFUNDED = 'refunded';

    protected $fillable = [
        'order_id',
        'user_id',
        'provider',
        'provider_reference',
        'transaction_id',
        'amount',
        'refunded_amount',
        'currency',
        'status',
        'failure_reason',
        'gateway_response',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'gateway_response' => 'array',
        'paid_at' => 'datetime',
    ];

    protected $hidden = ['gateway_response'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }
}
