<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartCourse;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\OrderProduct;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CouponService;
use App\Services\CourseAccessService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly CouponService $coupons,
        private readonly PaymentService $payments
    ) {
    }

    /** Return the order created earlier with the same Idempotency-Key (double-click / retry safe). */
    private function existingOrder(Request $request): ?Order
    {
        $key = $request->header('Idempotency-Key');

        if (! $key) {
            return null;
        }

        return Order::with('items', 'courseItems', 'statusHistories')
            ->where('user_id', $request->user()->id)
            ->where('idempotency_key', substr($key, 0, 100))
            ->first();
    }

    public function addOrder(Request $request)
    {
        if ($existing = $this->existingOrder($request)) {
            return $this->success('Order placed successfully', $this->formatOrder($existing));
        }

        $cart = Cart::with(['product.images', 'variant.attributes'])
            ->where('user_id', $request->user()->id)
            ->where('is_selected', true)
            ->get();

        $cartCourses = CartCourse::with('course')
            ->where('user_id', $request->user()->id)
            ->where('is_selected', true)
            ->get();

        if ($cart->isEmpty() && $cartCourses->isEmpty()) {
            return $this->error('No items selected for order', 400);
        }

        try {
            $order = DB::transaction(function () use ($request, $cart, $cartCourses) {
                $order = $this->createOrder($request, $request->user()->id);

                foreach ($cart as $item) {
                    $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
                    if (!$product) {
                        throw new \Exception('Product not found: ' . $item->product_id);
                    }

                    $variant = null;
                    $availableStock = null;

                    if ($item->variant_id) {
                        $variant = ProductVariant::where('id', $item->variant_id)
                            ->lockForUpdate()
                            ->first();
                        if (!$variant) {
                            throw new \Exception('Variant not found: ' . $item->variant_id);
                        }
                        $availableStock = $variant->stock;
                    } else {
                        $availableStock = $product->stock;
                    }

                    if ($availableStock !== null && $item->quantity > $availableStock) {
                        $itemName = $product->name . ($variant ? ' (' . $variant->sku . ')' : '');
                        throw new \Exception('Insufficient stock for product: ' . $itemName);
                    }

                    $this->createOrderItem($order->id, $product, $variant, $item->quantity, $item->price);

                    if ($availableStock !== null) {
                        $decrement = min($item->quantity, $availableStock);
                        if ($decrement > 0) {
                            if ($variant) {
                                $variant->decrement('stock', $decrement);
                            } else {
                                $product->decrement('stock', $decrement);
                            }
                        }
                    }
                }

                foreach ($cartCourses as $item) {
                    $course = Course::lockForUpdate()->find($item->course_id);

                    if (!$course || !$course->isPublished()) {
                        throw new \Exception('Course is not available: ' . $item->course_id);
                    }

                    $this->guardNotEnrolled($request->user(), $course);
                    $this->createOrderCourseItem($order->id, $course);
                }

                Cart::where('user_id', $request->user()->id)
                    ->where('is_selected', true)
                    ->delete();

                CartCourse::where('user_id', $request->user()->id)
                    ->where('is_selected', true)
                    ->delete();

                $this->finalizeNewOrder($order, $request);

                return $order;
            });
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 400);
        }

        return $this->success(
            'Order placed successfully',
            $this->formatOrder($order->fresh()->load('items', 'courseItems', 'statusHistories'))
        );
    }

    public function directOrder(Request $request)
    {
        if ($existing = $this->existingOrder($request)) {
            return $this->success('Order placed successfully', $this->formatOrder($existing));
        }

        $items = $request->items;
        $courseIds = $request->input('course_ids', []);

        if (! is_array($courseIds)) {
            $courseIds = [$courseIds];
        }

        if ((! $items || ! is_array($items)) && $request->filled('product_id')) {
            $items = [[
                'product_id' => $request->product_id,
                'variant_id' => $request->variant_id ?? null,
                'quantity' => $request->quantity,
            ]];
        }

        if (empty($items) && empty($courseIds)) {
            return $this->error('No items provided', 400);
        }

        $request->validate([
            'payment_method' => 'required|string',
            'course_ids' => 'nullable|array',
            'course_ids.*' => 'integer|exists:courses,id',
        ]);

        $errors = [];
        $validatedItems = [];
        $validatedCourses = [];

        foreach ($items ?: [] as $index => $item) {
            $productId = $item['product_id'] ?? null;
            $variantId = $item['variant_id'] ?? null;
            $quantity = $item['quantity'] ?? 1;

            if (!$productId) {
                $errors[] = 'Item ' . ($index + 1) . ': Product ID is required';
                continue;
            }

            if (!is_numeric($quantity) || $quantity < 1) {
                $errors[] = 'Item ' . ($index + 1) . ': Quantity must be at least 1';
                continue;
            }

            $product = Product::with('images')->find($productId);
            if (!$product) {
                $errors[] = 'Item ' . ($index + 1) . ': Product not found';
                continue;
            }

            $variant = null;
            $price = null;
            $availableStock = null;

            if ($variantId) {
                $variant = ProductVariant::where('id', $variantId)
                    ->where('product_id', $productId)
                    ->with('attributes')
                    ->first();

                if (!$variant) {
                    $errors[] = 'Item ' . ($index + 1) . ': Variant not found';
                    continue;
                }

                $price = $price ?? ($variant->offer_price ?? $variant->price);
                $availableStock = $variant->stock;
            } else {
                if ($product->variants()->exists()) {
                    $errors[] = 'Item ' . ($index + 1) . ': Please select a variant';
                    continue;
                }
                $price = $price ?? ($product->offer_price ?? $product->price);
                $availableStock = $product->stock;
            }

            if ($availableStock !== null && $quantity > $availableStock) {
                $errors[] = 'Item ' . ($index + 1) . ': Insufficient stock';
                continue;
            }

            $validatedItems[] = [
                'product' => $product,
                'variant' => $variant,
                'quantity' => $quantity,
                'price' => $price,
            ];
        }

        if (empty($validatedItems) && empty($errors) && empty($courseIds)) {
            return $this->error('No items provided', 400);
        }

        if (empty($validatedItems) && !empty($errors)) {
            return $this->error(implode('. ', $errors), 400);
        }

        foreach ($courseIds as $index => $courseId) {
            $course = Course::query()->published()->find($courseId);

            if (! $course) {
                $errors[] = 'Course ' . ($index + 1) . ': not available';
                continue;
            }

            $validatedCourses[] = $course;
        }

        if (empty($validatedItems) && empty($validatedCourses)) {
            return $this->error(implode('. ', $errors) ?: 'No valid items provided', 400);
        }

        try {
            $order = DB::transaction(function () use ($request, $validatedItems, $validatedCourses) {
                $order = $this->createOrder($request, $request->user()->id);

                foreach ($validatedItems as $item) {
                    $product = Product::where('id', $item['product']->id)->lockForUpdate()->first();

                    $variant = null;
                    $availableStock = null;

                    if ($item['variant']) {
                        $variant = ProductVariant::where('id', $item['variant']->id)->lockForUpdate()->first();
                        $availableStock = $variant ? $variant->stock : null;
                    } else {
                        $availableStock = $product ? $product->stock : null;
                    }

                    if ($availableStock !== null && $item['quantity'] > $availableStock) {
                        throw new \Exception('Insufficient stock for product: ' . ($product->name ?? ''));
                    }

                    $this->createOrderItem($order->id, $product, $item['variant'], $item['quantity'], $item['price']);

                    if ($availableStock !== null) {
                        $decrement = min($item['quantity'], $availableStock);
                        if ($decrement > 0) {
                            if ($variant) {
                                $variant->decrement('stock', $decrement);
                            } elseif ($product) {
                                $product->decrement('stock', $decrement);
                            }
                        }
                    }
                }

                foreach ($validatedCourses as $course) {
                    $locked = Course::lockForUpdate()->find($course->id);

                    if (! $locked || ! $locked->isPublished()) {
                        throw new \Exception('Course is not available: ' . $course->id);
                    }

                    $this->guardNotEnrolled($request->user(), $locked);
                    $this->createOrderCourseItem($order->id, $locked);
                }

                $this->finalizeNewOrder($order, $request);

                return $order;
            });
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 400);
        }

        return $this->success(
            'Order placed successfully',
            $this->formatOrder($order->fresh()->load('items', 'courseItems', 'statusHistories'))
        );
    }

    public function getOrder(Request $request)
    {
        $orders = Order::with('items', 'courseItems', 'statusHistories')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return $this->success('Orders retrieved successfully', $orders->map(fn($o) => $this->formatOrder($o)));
    }

    public function getAdminOrders(Request $request)
    {
        $orders = Order::with('items', 'courseItems', 'statusHistories', 'user')
            ->latest()
            ->get();

        return $this->success('Orders retrieved successfully', $orders->map(fn($o) => $this->formatOrder($o)));
    }

    public function getAdminOrder(Request $request, $id)
    {
        $order = Order::with('items', 'courseItems', 'statusHistories', 'user')->find($id);

        if (!$order) {
            return $this->error('Order not found', 404);
        }

        return $this->success('Order retrieved successfully', $this->formatOrder($order));
    }

    /** Statuses an admin may set. "shipped" & co. exist only for store products; "enrolled" only for courses. */
    private function allowedStatuses(Order $order): array
    {
        $hasProducts = $order->items->isNotEmpty();
        $hasCourses = $order->courseItems->isNotEmpty();

        $statuses = $hasCourses && ! $hasProducts
            ? ['pending', 'confirmed', 'enrolled', 'cancelled']
            : ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];

        // Never reject the order's current value (e.g. a refunded order being re-saved).
        return array_values(array_unique([...$statuses, $order->status]));
    }

    public function updateAdminOrder(Request $request)
    {
        $order = Order::with('items', 'courseItems')->find($request->id);
        if (!$order) {
            return $this->error('Order not found', 404);
        }

        $request->validate([
            'status' => ['nullable', \Illuminate\Validation\Rule::in($this->allowedStatuses($order))],
            'payment_status' => 'nullable|in:pending,paid,failed,cancelled',
        ]);

        $oldStatus = $order->status;

        // 1. Payment first, so "paid + enrolled" can be saved in one go.
        if ($request->input('payment_status') === 'paid' && $order->payment_status !== 'paid') {
            $this->payments->confirmManually($order, $request->user(), $request->input('payment_reference'));
            $order->refresh();
        } elseif ($request->has('payment_status') && $request->payment_status !== 'paid' && $order->payment_status !== 'paid') {
            $order->payment_status = $request->payment_status;
            $order->save();
        }

        // 2. "Enrolled" actually grants access (and fails loudly when it cannot).
        if ($request->input('status') === 'enrolled' && $oldStatus !== 'enrolled') {
            try {
                $this->payments->enrollOrder($order, $request->user());
            } catch (\DomainException $e) {
                return $this->error($e->getMessage());
            }
            $order->refresh();
            $oldStatus = 'enrolled';
        }

        if ($request->has('status')) {
            $order->status = $request->status;
        }
        if ($request->has('notes')) {
            $order->notes = $request->notes;
        }
        if ($request->has('delivery_charge') && $order->items->isNotEmpty()) {
            $order->delivery_charge = max(0, (float) $request->delivery_charge);
        }
        if ($request->has('shipping_method')) {
            $order->shipping_method = $request->shipping_method;
        }
        $order->save();

        if ($request->has('status') && $request->status !== $oldStatus) {
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status' => $request->status,
                'label' => $this->statusLabel($request->status),
                'note' => $request->status_note ?? null,
                'created_by' => $request->user()?->name ?? 'admin',
                'created_at' => now(),
            ]);
        }
        $products = $request->products;
        if ($products) {
            foreach ($products as $product) {
                $orderProduct = OrderProduct::where('order_id', $order->id)
                    ->where('product_id', $product['id'])
                    ->first();
                if ($orderProduct && isset($product['pivot']['quantity'])) {
                    $orderProduct->quantity = $product['pivot']['quantity'];
                    $orderProduct->line_total = $orderProduct->quantity * $orderProduct->unit_price;
                    $orderProduct->save();
                }
            }
        }

        $this->updateOrderTotals($order, $request);

        $this->handleEnrollmentLifecycle($order);

        return $this->success(
            'Order updated successfully',
            $this->formatOrder($order->fresh()->load('items', 'courseItems', 'statusHistories'))
        );
    }

    /**
     * Revoke course access when an order is cancelled/returned. Enrollment is only
     * ever granted by a verified payment (PaymentService::fulfilOrder), never by status edits.
     */
    private function handleEnrollmentLifecycle(Order $order): void
    {
        $order->loadMissing('courseItems.course');

        if ($order->courseItems->isEmpty()) {
            return;
        }

        if (in_array($order->status, ['cancelled', 'returned', 'refunded'], true)) {
            $this->payments->voidOrder($order);

            return;
        }

        if ($order->payment_status === 'paid' && config('courses.enrollment_mode', 'manual') === 'auto') {
            $this->access->ensureEnrollmentsForOrder($order);
        }
    }

    private function guardNotEnrolled($user, Course $course): void
    {
        if ($this->access->hasActiveAccess($user, $course)) {
            throw new \Exception('You are already enrolled in "'.$course->title.'"');
        }
    }

    /**
     * Price the order from server data only, apply the coupon, and fulfil free orders.
     */
    private function finalizeNewOrder(Order $order, Request $request): void
    {
        $order->load('items', 'courseItems.course');

        $productsSubtotal = (float) $order->items->sum('line_total');
        $courseLines = $order->courseItems->map(fn ($item) => [
            'course_id' => $item->course_id,
            'category_id' => $item->course?->course_category_id,
            'amount' => (float) $item->line_total,
        ]);

        $order->discount = 0;
        $order->discount_type = 'fixed';

        if ($order->coupon_code) {
            $coupon = $this->coupons->find($order->coupon_code);

            if (! $coupon) {
                throw new \Exception('Invalid coupon code');
            }

            $result = $this->coupons->evaluate($coupon, $request->user(), $courseLines, $productsSubtotal);
            $order->coupon_code = $coupon->code;
            $order->discount = $result['discount'];
            $this->coupons->redeem($order, $coupon, $result['discount']);
        }

        // Courses are digital: no delivery charge for course-only orders.
        if ($order->items->isEmpty()) {
            $order->delivery_charge = 0;
        }

        $order->save();
        $this->updateOrderTotals($order, $request);

        if ((float) $order->total <= 0) {
            $this->payments->fulfilOrder($order->fresh(), null);
        } elseif ((bool) config('courses.enroll_on_order_placement', false)) {
            // Legacy/dev switch only: grants access before payment.
            $this->access->ensureEnrollmentsForOrder($order);
        }
    }

    private function createOrderCourseItem($orderId, Course $course): OrderCourse
    {
        return OrderCourse::create([
            'order_id' => $orderId,
            'course_id' => $course->id,
            'name' => $course->title,
            'slug' => $course->slug,
            'image' => $course->thumbnail,
            'original_price' => $course->price,
            'unit_price' => $course->effective_price,
            'discount' => max((float) $course->price - $course->effective_price, 0),
            'line_total' => $course->effective_price,
            'access_days' => $course->access_days,
        ]);
    }

    private function createOrder(Request $request, $userId): Order
    {
        $orderNumber = 'ORD-' . now()->format('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(8));

        $customer = $request->input('customer', []);
        $shippingAddress = $request->input('shipping_address', []);
        $pricing = $request->input('pricing', []);
        $shipping = $request->input('shipping', []);

        $order = new Order;
        $order->user_id = $userId;
        $order->order_number = $orderNumber;
        $order->status = 'pending';
        $order->payment_method = $request->input('payment_method', $pricing['payment_method'] ?? null);
        $order->payment_status = 'pending';

        $order->name = $customer['name'] ?? $request->name;
        $order->email = $customer['email'] ?? $request->email;
        $order->phone = $customer['phone'] ?? $request->phone;
        $order->phone_alt = $customer['phone_alt'] ?? $request->phone_alt;

        $order->line1 = $shippingAddress['line1'] ?? $request->line1;
        $order->line2 = $shippingAddress['line2'] ?? $request->line2;
        $order->district = $shippingAddress['district'] ?? $request->district;
        $order->city = $shippingAddress['city'] ?? $request->city;
        $order->country = $shippingAddress['country'] ?? $request->country;

        $order->currency = $pricing['currency'] ?? 'BDT';

        $order->delivery_charge = $pricing['delivery_charge'] ?? $request->delivery_charge;
        // Discounts are never taken from the client; the coupon is validated server-side.
        $order->discount_type = 'fixed';
        $order->discount = 0;
        $order->idempotency_key = $request->header('Idempotency-Key') ? substr($request->header('Idempotency-Key'), 0, 100) : null;

        $couponCode = $pricing['coupon_code'] ?? $request->coupon_code ?? $request->coupon;
        $order->coupon_code = $couponCode ? trim((string) $couponCode) : null;

        $order->shipping_method = $shipping['method'] ?? $request->shipping_method;
        $order->estimated_delivery_days = $shipping['estimated_delivery_days'] ?? $request->estimated_delivery_days;

        $order->notes = $request->notes;
        $order->save();

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'status' => 'pending',
            'label' => 'Order Placed',
            'note' => null,
            'created_by' => $request->user()?->name ?? 'customer',
            'created_at' => now(),
        ]);

        return $order;
    }

    private function createOrderItem($orderId, $product, $variant = null, $quantity, $price)
    {
        $unitPrice = $price;
        $originalPrice = $variant ? ($variant->price ?? $product->price) : $product->price;
        $discount = $originalPrice - $unitPrice;

        $featuredImage = $product->images->firstWhere('is_featured', true)
            ?? $product->images->first();

        $attributes = [];
        if ($variant) {
            $attributes = $variant->attributes->map(fn($a) => [
                'attribute' => $a->attribute,
                'value' => $a->value,
            ])->toArray();
        }

        $stockSnapshot = null;
        if ($variant) {
            $stockSnapshot = ['variant_id' => $variant->id, 'stock' => $variant->stock];
        } elseif ($product) {
            $stockSnapshot = ['product_id' => $product->id, 'stock' => $product->stock];
        }

        OrderProduct::create([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'variant_id' => $variant ? $variant->id : null,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $variant ? $variant->sku : null,
            'image' => $featuredImage ? $featuredImage->url : null,
            'attributes' => $attributes,
            'original_price' => $originalPrice,
            'unit_price' => $unitPrice,
            'discount' => max($discount, 0),
            'quantity' => $quantity,
            'price' => $price,
            'line_total' => $quantity * $unitPrice,
            'stock_snapshot' => $stockSnapshot,
        ]);
    }

    private function updateOrderTotals(Order $order, Request $request)
    {
        $items = $order->items()->get();
        $courseItems = $order->courseItems()->get();
        $totalQuantity = $items->sum('quantity');
        $totalItems = $items->count() + $courseItems->count();
        $subtotal = $items->sum('line_total') + $courseItems->sum('line_total');

        $order->total_items = $totalItems;
        $order->total_quantity = $totalQuantity;
        $order->subtotal = $subtotal;

        $discountedSubtotal = $subtotal;

        if ($order->discount > 0) {
            if ($order->discount_type === 'percentage') {
                $discountedSubtotal = $subtotal - ($subtotal * $order->discount / 100);
            } else {
                $discountedSubtotal = $subtotal - $order->discount;
            }
        }

        $order->total = max($discountedSubtotal, 0) + ($order->delivery_charge ?? 0);
        $order->save();
    }

    private function formatOrder($order): array
    {
        if (!$order) {
            return [];
        }

        $items = $order->items->map(fn($item) => [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'variant_id' => $item->variant_id,
            'name' => $item->name,
            'slug' => $item->slug,
            'sku' => $item->sku,
            'image' => $item->image,
            'attributes' => $item->attributes ?? [],
            'original_price' => (float) $item->original_price,
            'unit_price' => (float) $item->unit_price,
            'discount' => (float) $item->discount,
            'quantity' => (int) $item->quantity,
            'line_total' => (float) $item->line_total,
            'stock_snapshot' => $item->stock_snapshot,
        ])->values()->toArray();

        $enrolledCourseIds = \App\Models\Enrollment::query()
            ->where('user_id', $order->user_id)
            ->whereIn('course_id', $order->courseItems->pluck('course_id'))
            ->whereIn('status', ['active', 'completed'])
            ->pluck('course_id')
            ->all();

        $courseItems = $order->courseItems->map(fn($item) => [
            'enrolled' => in_array($item->course_id, $enrolledCourseIds, true),
            'id' => $item->id,
            'course_id' => $item->course_id,
            'name' => $item->name,
            'slug' => $item->slug,
            'image' => $item->image,
            'original_price' => (float) $item->original_price,
            'unit_price' => (float) $item->unit_price,
            'discount' => (float) $item->discount,
            'line_total' => (float) $item->line_total,
            'access_days' => $item->access_days,
        ])->values()->toArray();

        $statusHistory = $order->statusHistories->map(fn($h) => [            'status' => $h->status,
            'label' => $h->label ?? $this->statusLabel($h->status),
            'note' => $h->note,
            'created_by' => $h->created_by,
            'created_at' => $h->created_at ? $h->created_at->toIso8601String() : null,
        ])->values()->toArray();

        $user = $order->relationLoaded('user') ? $order->user : null;

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer_id' => $order->user_id,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'customer' => [
                'name' => $order->name ?? '',
                'email' => $order->email ?? '',
                'phone' => $order->phone ?? '',
                'phone_alt' => $order->phone_alt ?? '',
            ],
            'shipping_address' => [
                'line1' => $order->line1 ?? '',
                'line2' => $order->line2 ?? '',
                'district' => $order->district ?? '',
                'city' => $order->city ?? '',
                'country' => $order->country ?? '',
            ],
            'pricing' => [
                'currency' => $order->currency ?? 'BDT',
                'subtotal' => (float) ($order->subtotal ?? 0),
                'products_subtotal' => round(collect($items)->sum('line_total'), 2),
                'courses_subtotal' => round(collect($courseItems)->sum('line_total'), 2),
                'delivery_charge' => (float) ($order->delivery_charge ?? 0),
                'discount' => (float) ($order->discount ?? 0),
                'discount_type' => $order->discount_type,
                'coupon_code' => $order->coupon_code,
                'total' => (float) ($order->total ?? 0),
                'refunded_amount' => (float) ($order->refunded_amount ?? 0),
            ],
            'shipping' => [
                'method' => $order->shipping_method,
                'estimated_delivery_days' => $order->estimated_delivery_days,
            ],
            'summary' => [
                'total_items' => (int) ($order->total_items ?? 0),
                'total_quantity' => (int) ($order->total_quantity ?? 0),
            ],
            'items' => $items,
            'course_items' => $courseItems,
            'status_history' => $statusHistory,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'notes' => $order->notes,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }

    private function statusLabel($status): string
    {
        return match ($status) {
            'pending' => 'Order Placed',
            'confirmed' => 'Order Confirmed',
            'processing' => 'Processing',
            'shipped' => 'Shipped',
            'delivered' => 'Delivered',
            'enrolled' => 'Enrolled',
            'cancelled' => 'Cancelled',
            'returned' => 'Returned',
            'refunded' => 'Refunded',
            default => ucfirst($status),
        };
    }
}
