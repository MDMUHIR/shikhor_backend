<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    /** Price always comes from the catalog, never from the client. */
    private function currentPrice(Product $product, ?ProductVariant $variant): float
    {
        return (float) ($variant ? ($variant->offer_price ?? $variant->price) : ($product->offer_price ?? $product->price));
    }

    private function stockOf(Product $product, ?ProductVariant $variant): ?int
    {
        $stock = $variant ? $variant->stock : $product->stock;

        return $stock === null ? null : (int) $stock;
    }

    public function getCart(Request $request)
    {
        $cart = Cart::with(['product.images', 'variant.images', 'variant.attributes'])
            ->where('user_id', $request->user()->id)
            ->orderBy('id')
            ->get();

        // Keep stored prices in step with the catalog so the cart page shows what checkout will charge.
        foreach ($cart as $item) {
            if (! $item->product) {
                continue;
            }

            $price = $this->currentPrice($item->product, $item->variant);

            if ((float) $item->price !== $price) {
                $item->forceFill(['price' => $price, 'total' => $price * $item->quantity])->save();
            }
        }

        return $this->success('Get cart', $cart);
    }

    public function addToCart(Request $request)
    {
        $items = $request->items;

        if (! $items || ! is_array($items)) {
            $this->validate($request, [
                'product_id' => 'required|integer',
                'quantity' => 'nullable|integer|min:1|max:999',
                'variant_id' => 'nullable|integer',
                'buy_now' => 'nullable|boolean',
            ]);

            $items = [[
                'product_id' => $request->product_id,
                'variant_id' => $request->variant_id ?? null,
                'quantity' => $request->input('quantity', 1),
            ]];
        }

        $buyNow = $request->boolean('buy_now');
        $user = $request->user();
        $addedItems = [];
        $errors = [];

        DB::transaction(function () use ($items, $buyNow, $user, &$addedItems, &$errors) {
            foreach ($items as $index => $item) {
                $label = 'Item '.($index + 1).': ';
                $productId = $item['product_id'] ?? null;
                $variantId = $item['variant_id'] ?? null;
                $quantity = $item['quantity'] ?? 1;

                if (! $productId) {
                    $errors[] = $label.'Product ID is required';

                    continue;
                }

                if (! is_numeric($quantity) || (int) $quantity < 1) {
                    $errors[] = $label.'Quantity must be at least 1';

                    continue;
                }

                $quantity = (int) $quantity;
                $product = Product::find($productId);

                if (! $product || $product->status !== 'active') {
                    $errors[] = $label.'This product is not available';

                    continue;
                }

                $variant = null;

                if ($variantId) {
                    $variant = ProductVariant::where('id', $variantId)->where('product_id', $productId)->first();

                    if (! $variant) {
                        $errors[] = $label.'Variant not found';

                        continue;
                    }
                } elseif ($product->variants()->exists()) {
                    $errors[] = $label.'Please select an option';

                    continue;
                }

                $stock = $this->stockOf($product, $variant);

                if ($stock !== null && $stock < 1) {
                    $errors[] = $label.'Out of stock';

                    continue;
                }

                $existing = Cart::where('user_id', $user->id)
                    ->where('product_id', $productId)
                    ->when($variantId, fn ($q) => $q->where('variant_id', $variantId), fn ($q) => $q->whereNull('variant_id'))
                    ->first();

                // "Buy now" means exactly this quantity; "add to cart" adds on top of what is there.
                $newQuantity = ($existing && ! $buyNow) ? $existing->quantity + $quantity : $quantity;

                if ($stock !== null && $newQuantity > $stock) {
                    $errors[] = $label.($stock > 0 ? "Only {$stock} in stock" : 'Out of stock');

                    continue;
                }

                $price = $this->currentPrice($product, $variant);
                $cart = $existing ?? new Cart(['user_id' => $user->id, 'product_id' => $productId, 'variant_id' => $variantId]);

                $cart->quantity = $newQuantity;
                $cart->price = $price;
                $cart->total = $price * $newQuantity;
                $cart->is_selected = true; // just added = wanted at checkout
                $cart->save();

                $addedItems[] = $cart;
            }

            // Buy now checks out this item alone: everything else stays in the cart, unselected.
            if ($buyNow && $addedItems) {
                Cart::where('user_id', $user->id)
                    ->whereNotIn('id', collect($addedItems)->pluck('id'))
                    ->update(['is_selected' => false]);

                \App\Models\CartCourse::where('user_id', $user->id)->update(['is_selected' => false]);
            }
        });

        if (empty($addedItems) && ! empty($errors)) {
            return $this->error(implode('. ', $errors), 400);
        }

        if (! empty($errors)) {
            return $this->success('Some items added to cart with warnings', [
                'added' => $addedItems,
                'warnings' => $errors,
            ]);
        }

        return $this->success('Added to cart', $addedItems);
    }

    public function updateCart(Request $request)
    {
        $this->validate($request, [
            'cart_id' => 'required|integer',
            'quantity' => 'required|integer|min:1|max:999',
        ]);

        $cart = Cart::with(['product', 'variant'])
            ->where('user_id', $request->user()->id)
            ->where('id', $request->cart_id)
            ->first();

        if (! $cart || ! $cart->product) {
            return $this->error('Cart item not found', 404);
        }

        $stock = $this->stockOf($cart->product, $cart->variant);

        if ($stock !== null && $request->quantity > $stock) {
            return $this->error($stock > 0 ? "Only {$stock} in stock" : 'Out of stock', 400);
        }

        $price = $this->currentPrice($cart->product, $cart->variant);

        $cart->quantity = (int) $request->quantity;
        $cart->price = $price;
        $cart->total = $price * $cart->quantity;
        $cart->save();

        return $this->success('Update cart', $cart->load(['product.images', 'variant.images']));
    }

    public function deleteCart(Request $request, $id)
    {
        $cart = Cart::where('user_id', $request->user()->id)->where('id', $id)->first();

        if (! $cart) {
            return $this->error('Cart item not found', 404);
        }

        $cart->delete();

        return $this->success('Delete cart', null);
    }

    public function toggleSelection(Request $request)
    {
        $cart = Cart::where('user_id', $request->user()->id)->where('id', $request->cart_id)->first();

        if (! $cart) {
            return $this->error('Cart item not found', 404);
        }

        $cart->is_selected = $request->has('is_selected') ? $request->boolean('is_selected') : ! $cart->is_selected;
        $cart->save();

        return $this->success('Cart selection updated', $cart);
    }

    public function selectAll(Request $request)
    {
        $count = Cart::where('user_id', $request->user()->id)->where('is_selected', false)->update(['is_selected' => true]);

        return $this->success("Selected $count items for checkout", null);
    }

    public function deselectAll(Request $request)
    {
        $count = Cart::where('user_id', $request->user()->id)->where('is_selected', true)->update(['is_selected' => false]);

        return $this->success("Deselected $count items", null);
    }
}
