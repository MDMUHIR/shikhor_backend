<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\LmsNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * All payment state transitions go through here so that fulfilment
 * (order paid -> enrollment) is atomic and idempotent.
 */
class PaymentService
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly CouponService $coupons
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    */

    /**
     * Start (or reuse) a payment for an unpaid order. The amount always comes from the order.
     *
     * @return array{payment: Payment, checkout_url: ?string}
     */
    public function initiate(Order $order, string $provider): array
    {
        if (! in_array($provider, ['manual', 'stripe'], true)) {
            throw new \DomainException('Unsupported payment provider');
        }

        return DB::transaction(function () use ($order, $provider) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->payment_status === 'paid') {
                throw new \DomainException('This order has already been paid');
            }

            if (in_array($order->status, ['cancelled', 'refunded', 'returned'], true)) {
                throw new \DomainException('This order can no longer be paid');
            }

            if ((float) $order->total <= 0) {
                throw new \DomainException('This order does not require payment');
            }

            // Reuse a live pending payment so repeated clicks cannot create duplicates.
            $payment = Payment::query()
                ->where('order_id', $order->id)
                ->where('provider', $provider)
                ->whereIn('status', [Payment::PENDING, Payment::PROCESSING])
                ->latest('id')
                ->first();

            if ($payment && $provider === 'stripe' && ! empty($payment->gateway_response['checkout_url'])) {
                return ['payment' => $payment, 'checkout_url' => $payment->gateway_response['checkout_url']];
            }

            $payment ??= Payment::create([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'provider' => $provider,
                'amount' => $order->total,
                'currency' => $order->currency ?: 'BDT',
                'status' => Payment::PENDING,
            ]);

            if ($provider === 'manual') {
                return ['payment' => $payment, 'checkout_url' => null];
            }

            $session = $this->createStripeSession($order, $payment);

            $payment->forceFill([
                'provider_reference' => $session['id'],
                'status' => Payment::PROCESSING,
                'gateway_response' => ['checkout_url' => $session['url']],
            ])->save();

            return ['payment' => $payment, 'checkout_url' => $session['url']];
        });
    }

    private function createStripeSession(Order $order, Payment $payment): array
    {
        $secret = config('services.stripe.secret');

        if (! $secret) {
            throw new \DomainException('Online payments are not configured');
        }

        $frontend = rtrim(config('courses.payments.frontend_url'), '/');

        $response = Http::withToken($secret)->asForm()->post('https://api.stripe.com/v1/checkout/sessions', [
            'mode' => 'payment',
            'success_url' => $frontend.'/checkout/success?order='.$order->order_number,
            'cancel_url' => $frontend.'/checkout/cancel?order='.$order->order_number,
            'client_reference_id' => (string) $order->id,
            'customer_email' => $order->email ?: null,
            'metadata[order_id]' => $order->id,
            'metadata[payment_id]' => $payment->id,
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => strtolower($payment->currency),
            'line_items[0][price_data][unit_amount]' => $this->minorUnits($payment->amount),
            'line_items[0][price_data][product_data][name]' => 'Order '.$order->order_number,
        ]);

        if (! $response->successful() || ! $response->json('id')) {
            Log::warning('Stripe checkout session failed', ['order' => $order->id, 'status' => $response->status()]);

            throw new \DomainException('Could not start the payment. Please try again.');
        }

        return ['id' => $response->json('id'), 'url' => $response->json('url')];
    }

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    */

    /** Verify a Stripe-style signature header: "t=timestamp,v1=hmac_sha256(t.payload)". */
    public function verifyStripeSignature(string $payload, ?string $header): bool
    {
        $secret = config('services.stripe.webhook_secret');

        if (! $secret || ! $header) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if (! $timestamp || ! ctype_digit($timestamp) || empty($signatures)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > config('courses.payments.webhook_tolerance_seconds', 300)) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, (string) $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process a verified Stripe event exactly once.
     */
    public function handleStripeEvent(array $event): string
    {
        $eventId = $event['id'] ?? null;

        if (! $eventId) {
            return 'ignored';
        }

        try {
            $record = PaymentEvent::create([
                'provider' => 'stripe',
                'event_id' => $eventId,
                'type' => $event['type'] ?? null,
                'payload' => $event,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = PaymentEvent::where('provider', 'stripe')->where('event_id', $eventId)->first();

            // A previous delivery that crashed before finishing is retried; completed ones are skipped.
            if ($existing && $existing->processed_at !== null) {
                return 'duplicate';
            }

            $record = $existing;
        }

        $object = $event['data']['object'] ?? [];

        match ($event['type'] ?? '') {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->onStripePaid($object),
            'checkout.session.expired', 'checkout.session.async_payment_failed' => $this->onStripeFailed($object, $event['type']),
            default => null,
        };

        $record->forceFill(['processed_at' => now()])->save();

        return 'processed';
    }

    private function onStripePaid(array $session): void
    {
        if (($session['payment_status'] ?? '') !== 'paid') {
            return;
        }

        $payment = Payment::query()
            ->where('provider', 'stripe')
            ->where('provider_reference', $session['id'] ?? '')
            ->first();

        if (! $payment) {
            Log::warning('Stripe webhook for unknown payment', ['session' => $session['id'] ?? null]);

            return;
        }

        $this->markPaid(
            $payment,
            $session['payment_intent'] ?? null,
            isset($session['amount_total']) ? (int) $session['amount_total'] : null,
            isset($session['currency']) ? strtoupper($session['currency']) : null
        );
    }

    private function onStripeFailed(array $session, string $type): void
    {
        $payment = Payment::query()
            ->where('provider', 'stripe')
            ->where('provider_reference', $session['id'] ?? '')
            ->whereIn('status', [Payment::PENDING, Payment::PROCESSING])
            ->first();

        $payment?->forceFill([
            'status' => $type === 'checkout.session.expired' ? Payment::CANCELLED : Payment::FAILED,
            'failure_reason' => $type,
        ])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | State transitions
    |--------------------------------------------------------------------------
    */

    /**
     * Mark a payment successful, verifying the amount, then fulfil the order. Idempotent.
     */
    public function markPaid(Payment $payment, ?string $transactionId = null, ?int $paidMinorUnits = null, ?string $currency = null, ?User $confirmedBy = null): Payment
    {
        return DB::transaction(function () use ($payment, $transactionId, $paidMinorUnits, $currency, $confirmedBy) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);

            if ($payment->status === Payment::SUCCESSFUL || $payment->status === Payment::REFUNDED) {
                return $payment;
            }

            // Never trust the gateway blindly: it must have collected what the order costs.
            if ($paidMinorUnits !== null && $paidMinorUnits !== $this->minorUnits($payment->amount)) {
                $payment->forceFill(['status' => Payment::FAILED, 'failure_reason' => 'amount_mismatch'])->save();
                Log::error('Payment amount mismatch', ['payment' => $payment->id, 'paid' => $paidMinorUnits]);

                return $payment;
            }

            if ($currency !== null && strtoupper($payment->currency) !== $currency) {
                $payment->forceFill(['status' => Payment::FAILED, 'failure_reason' => 'currency_mismatch'])->save();

                return $payment;
            }

            if ((float) $order->total !== (float) $payment->amount) {
                $payment->forceFill(['status' => Payment::FAILED, 'failure_reason' => 'order_total_changed'])->save();

                return $payment;
            }

            $payment->forceFill([
                'status' => Payment::SUCCESSFUL,
                'transaction_id' => $transactionId ?? $payment->transaction_id,
                'paid_at' => now(),
                'failure_reason' => null,
            ])->save();

            $this->fulfilOrder($order, $confirmedBy);

            return $payment;
        });
    }

    /**
     * Admin confirms an offline/manual payment (bank transfer, cash, bKash...).
     */
    public function confirmManually(Order $order, User $admin, ?string $reference = null): Payment
    {
        return DB::transaction(function () use ($order, $admin, $reference) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            $existing = Payment::query()
                ->where('order_id', $order->id)
                ->where('status', Payment::SUCCESSFUL)
                ->first();

            if ($existing) {
                $this->fulfilOrder($order, $admin);

                return $existing;
            }

            $payment = Payment::query()
                ->where('order_id', $order->id)
                ->where('provider', 'manual')
                ->whereIn('status', [Payment::PENDING, Payment::PROCESSING])
                ->first()
                ?? Payment::create([
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'provider' => 'manual',
                    'amount' => $order->total,
                    'currency' => $order->currency ?: 'BDT',
                    'status' => Payment::PENDING,
                ]);

            $payment->forceFill(['amount' => $order->total])->save();

            return $this->markPaid($payment, $reference, null, null, $admin);
        });
    }

    /**
     * Mark the order paid and grant course access. Idempotent: running twice changes nothing.
     */
    public function fulfilOrder(Order $order, ?User $actor): void
    {
        $firstTime = $order->payment_status !== 'paid';

        if ($firstTime) {
            $order->forceFill([
                'payment_status' => 'paid',
                'paid_at' => now(),
                'status' => $order->status === 'pending' ? 'confirmed' : $order->status,
            ])->save();

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status' => $order->status,
                'label' => 'Payment received',
                'note' => null,
                'created_by' => $actor?->name ?? 'system',
                'created_at' => now(),
            ]);
        }

        $order->load('courseItems.course', 'user');

        $auto = config('courses.enrollment_mode', 'manual') === 'auto';

        if ($auto) {
            $this->access->ensureEnrollmentsForOrder($order);
        }

        if ($firstTime && $order->user) {
            $order->user->notify(new LmsNotification(
                'payment_received',
                'Payment received',
                'Your payment for order '.$order->order_number.' was successful.',
                ['order_id' => $order->id]
            ));

            foreach ($auto ? $order->courseItems : [] as $item) {
                $order->user->notify(new LmsNotification(
                    'course_enrolled',
                    'You are enrolled',
                    'You now have access to "'.$item->name.'".',
                    ['course_id' => $item->course_id, 'slug' => $item->slug]
                ));
            }
        }
    }

    /**
     * Admin approves the order and enrolls the student manually. Idempotent.
     */
    public function enrollOrder(Order $order, User $admin, bool $allowUnpaid = false): Order
    {
        return DB::transaction(function () use ($order, $admin, $allowUnpaid) {
            $order = Order::query()->lockForUpdate()->with('courseItems.course', 'user')->findOrFail($order->id);

            if ($order->courseItems->isEmpty()) {
                throw new \DomainException('This order has no courses');
            }

            if (in_array($order->status, ['cancelled', 'refunded', 'returned'], true)) {
                throw new \DomainException('A '.$order->status.' order cannot be enrolled');
            }

            if ($order->payment_status !== 'paid' && (float) $order->total > 0 && ! $allowUnpaid) {
                throw new \DomainException('Confirm the payment before enrolling the student');
            }

            $courseOnly = $order->items()->doesntExist();
            $target = $courseOnly ? 'enrolled' : 'confirmed';

            if ($order->status !== $target && ($courseOnly ? in_array($order->status, ['pending', 'confirmed', 'processing'], true) : $order->status === 'pending')) {
                $order->forceFill(['status' => $target])->save();

                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'status' => $target,
                    'label' => $courseOnly ? 'Enrolled' : 'Approved & enrolled',
                    'note' => null,
                    'created_by' => $admin->name,
                    'created_at' => now(),
                ]);
            }

            $this->access->ensureEnrollmentsForOrder($order);

            foreach ($order->courseItems as $item) {
                $order->user?->notify(new LmsNotification(
                    'course_enrolled',
                    'You are enrolled',
                    'You now have access to "'.$item->name.'".',
                    ['course_id' => $item->course_id, 'slug' => $item->slug]
                ));
            }

            return $order;
        });
    }

    /**
     * Release access/coupon for an order that is cancelled before payment or after a full refund.
     */
    public function voidOrder(Order $order): void
    {
        $this->access->revokeForOrder($order);
        $this->coupons->release($order);

        foreach ($order->courseItems as $item) {
            $item->course?->refreshStudentCount();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Refunds
    |--------------------------------------------------------------------------
    */

    public function refund(Payment $payment, ?float $amount, ?string $reason, User $admin): Refund
    {
        return DB::transaction(function () use ($payment, $amount, $reason, $admin) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $order = Order::query()->lockForUpdate()->with('courseItems.course')->findOrFail($payment->order_id);

            if (! in_array($payment->status, [Payment::SUCCESSFUL], true) && (float) $payment->refunded_amount <= 0) {
                throw new \DomainException('Only successful payments can be refunded');
            }

            $refundable = round((float) $payment->amount - (float) $payment->refunded_amount, 2);
            $amount = $amount === null ? $refundable : round($amount, 2);

            if ($amount <= 0 || $amount > $refundable) {
                throw new \DomainException('Refund amount must be between 0.01 and '.number_format($refundable, 2, '.', ''));
            }

            $providerRefundId = null;

            if ($payment->provider === 'stripe') {
                $providerRefundId = $this->createStripeRefund($payment, $amount);
            }

            $refund = Refund::create([
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'amount' => $amount,
                'reason' => $reason,
                'status' => 'completed',
                'provider_refund_id' => $providerRefundId,
                'created_by' => $admin->id,
            ]);

            $totalRefunded = round((float) $payment->refunded_amount + $amount, 2);
            $full = $totalRefunded >= (float) $payment->amount;

            $payment->forceFill([
                'refunded_amount' => $totalRefunded,
                'status' => $full ? Payment::REFUNDED : Payment::SUCCESSFUL,
            ])->save();

            $order->forceFill([
                'refunded_amount' => $totalRefunded,
                'payment_status' => $full ? 'refunded' : 'partially_refunded',
                'status' => $full ? 'refunded' : $order->status,
            ])->save();

            if ($full) {
                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'status' => 'refunded',
                    'label' => 'Refunded',
                    'note' => $reason,
                    'created_by' => $admin->name,
                    'created_at' => now(),
                ]);

                $this->voidOrder($order);
            }

            return $refund;
        });
    }

    private function createStripeRefund(Payment $payment, float $amount): string
    {
        $secret = config('services.stripe.secret');

        if (! $secret || ! $payment->transaction_id) {
            throw new \DomainException('This payment cannot be refunded automatically');
        }

        $response = Http::withToken($secret)->asForm()->post('https://api.stripe.com/v1/refunds', [
            'payment_intent' => $payment->transaction_id,
            'amount' => $this->minorUnits($amount),
        ]);

        if (! $response->successful()) {
            throw new \DomainException('The payment provider rejected the refund');
        }

        return (string) $response->json('id');
    }

    private function minorUnits(float|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
