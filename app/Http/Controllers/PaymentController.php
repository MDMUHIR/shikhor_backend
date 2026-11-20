<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Student
    |--------------------------------------------------------------------------
    */

    /** Start a payment for one of the student's own orders. Amount is taken from the order. */
    public function pay(Request $request, $id)
    {
        $request->validate([
            'provider' => 'nullable|in:manual,stripe',
        ]);

        $order = Order::query()->where('user_id', $request->user()->id)->find($id);

        if (! $order) {
            return $this->error('Order not found', 404);
        }

        try {
            $result = $this->payments->initiate(
                $order,
                $request->input('provider', config('courses.payments.default_provider', 'manual'))
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('Payment started', [
            'payment' => $this->format($result['payment']),
            'checkout_url' => $result['checkout_url'],
        ]);
    }

    public function mine(Request $request)
    {
        $payments = Payment::query()
            ->with('order:id,order_number')
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $payments->setCollection($payments->getCollection()->map(fn (Payment $p) => $this->format($p)));

        return $this->success('Payments retrieved successfully', $payments);
    }

    /*
    |--------------------------------------------------------------------------
    | Webhooks (public, signature verified)
    |--------------------------------------------------------------------------
    */

    public function webhook(Request $request, string $provider)
    {
        if ($provider !== 'stripe') {
            return response()->json(['message' => 'Unknown provider'], 404);
        }

        $payload = $request->getContent();

        if (! $this->payments->verifyStripeSignature($payload, $request->header('Stripe-Signature'))) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $result = $this->payments->handleStripeEvent($event);

        return response()->json(['received' => true, 'result' => $result]);
    }

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    */

    public function adminIndex(Request $request)
    {
        $query = Payment::query()->with(['order:id,order_number,total', 'user:id,name,email']);

        foreach (['status', 'provider', 'order_id', 'user_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $payments = $query->latest('id')->paginate($this->perPage($request))->withQueryString();
        $payments->setCollection($payments->getCollection()->map(fn (Payment $p) => $this->format($p, true)));

        return $this->success('Payments retrieved successfully', $payments);
    }

    public function adminShow($id)
    {
        $payment = Payment::with(['order:id,order_number,total', 'user:id,name,email', 'refunds'])->find($id);

        if (! $payment) {
            return $this->error('Payment not found', 404);
        }

        return $this->success('Payment retrieved successfully', $this->format($payment, true) + [
            'refunds' => $payment->refunds,
        ]);
    }

    /** Admin confirms an offline payment for an order. */
    public function confirm(Request $request, $orderId)
    {
        $request->validate(['reference' => 'nullable|string|max:255']);

        $order = Order::find($orderId);

        if (! $order) {
            return $this->error('Order not found', 404);
        }

        if (in_array($order->status, ['cancelled', 'refunded', 'returned'], true)) {
            return $this->error('This order can no longer be paid');
        }

        $payment = $this->payments->confirmManually($order, $request->user(), $request->reference);

        return $this->success('Payment confirmed', $this->format($payment->fresh()));
    }

    /** Approve an order and enroll the student in its courses (manual enrollment mode). */
    public function enroll(Request $request, $orderId)
    {
        $request->validate(['allow_unpaid' => 'nullable|boolean']);

        $order = Order::find($orderId);

        if (! $order) {
            return $this->error('Order not found', 404);
        }

        try {
            $this->payments->enrollOrder($order, $request->user(), $request->boolean('allow_unpaid'));
        } catch (\DomainException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('Student enrolled successfully', null);
    }

    public function refund(Request $request, $id)
    {
        $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
        ]);

        $payment = Payment::find($id);

        if (! $payment) {
            return $this->error('Payment not found', 404);
        }

        try {
            $refund = $this->payments->refund(
                $payment,
                $request->filled('amount') ? (float) $request->amount : null,
                $request->reason,
                $request->user()
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('Refund processed', [
            'refund' => $refund,
            'payment' => $this->format($payment->fresh()),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 20), 1), 100);
    }

    private function format(Payment $payment, bool $admin = false): array
    {
        $data = [
            'id' => $payment->id,
            'order_id' => $payment->order_id,
            'order_number' => $payment->relationLoaded('order') ? $payment->order?->order_number : null,
            'provider' => $payment->provider,
            'status' => $payment->status,
            'amount' => (float) $payment->amount,
            'refunded_amount' => (float) $payment->refunded_amount,
            'currency' => $payment->currency,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];

        if ($admin) {
            $data += [
                'user' => $payment->relationLoaded('user') && $payment->user
                    ? ['id' => $payment->user->id, 'name' => $payment->user->name, 'email' => $payment->user->email]
                    : null,
                'transaction_id' => $payment->transaction_id,
                'provider_reference' => $payment->provider_reference,
                'failure_reason' => $payment->failure_reason,
            ];
        }

        return $data;
    }
}
