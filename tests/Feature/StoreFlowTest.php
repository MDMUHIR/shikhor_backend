<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['type' => 'customer']);
        Sanctum::actingAs($this->user);
    }

    private function product(array $attrs = []): Product
    {
        return Product::create($attrs + [
            'name' => 'Book '.uniqid(),
            'slug' => 'book-'.uniqid(),
            'price' => 500,
            'offer_price' => null,
            'stock' => 10,
            'status' => 'active',
            'category_id' => Category::create(['name' => 'Books '.uniqid(), 'slug' => 'books-'.uniqid()])->id,
        ]);
    }

    private function checkoutBody(): array
    {
        return [
            'customer' => ['name' => 'Buyer', 'email' => $this->user->email, 'phone' => '017'],
            'shipping_address' => ['line1' => 'Road 1', 'district' => 'Dhaka', 'city' => 'Mirpur', 'country' => 'Bangladesh'],
            'pricing' => ['delivery_charge' => 60],
            'payment_method' => 'cod',
        ];
    }

    public function test_added_items_are_selected_so_the_order_can_be_placed_without_touching_the_quantity(): void
    {
        $product = $this->product();

        $this->postJson('api/cart/add', ['product_id' => $product->id, 'quantity' => 1])->assertOk()->assertJsonPath('status', true);

        $this->getJson('api/cart')->assertJsonPath('data.0.is_selected', true);

        $order = $this->postJson('api/orders/add', $this->checkoutBody())->assertOk()->json();
        $this->assertTrue($order['status'], $order['message'] ?? '');
        $this->assertEquals(1, $order['data']['items'][0]['quantity']);
        $this->assertSame(9, $product->fresh()->stock);
        $this->assertSame(0, Cart::count());
    }

    public function test_buy_now_sets_the_exact_quantity_and_selects_only_that_item(): void
    {
        $a = $this->product();
        $b = $this->product();

        $this->postJson('api/cart/add', ['product_id' => $a->id, 'quantity' => 2]);
        $this->postJson('api/cart/add', ['product_id' => $b->id, 'quantity' => 1]);

        $this->postJson('api/cart/add', ['product_id' => $a->id, 'quantity' => 3, 'buy_now' => true])->assertOk()->assertJsonPath('status', true);

        $this->assertSame(3, Cart::where('product_id', $a->id)->first()->quantity, 'buy now must not add on top of the old quantity');
        $this->assertTrue(Cart::where('product_id', $a->id)->first()->is_selected);
        $this->assertFalse(Cart::where('product_id', $b->id)->first()->is_selected);

        $this->postJson('api/orders/add', $this->checkoutBody())->assertOk()->assertJsonPath('status', true);
        $this->assertSame(7, $a->fresh()->stock);
        $this->assertSame(10, $b->fresh()->stock);
        $this->assertSame(1, Cart::count(), 'the unselected item stays in the cart');
    }

    public function test_server_price_is_used_and_client_price_ignored(): void
    {
        $this->seedZones();
        $product = $this->product(['price' => 500, 'offer_price' => 400]);

        $this->postJson('api/cart/add', ['product_id' => $product->id, 'quantity' => 1, 'price' => 1])->assertOk();
        $this->assertEquals(400, Cart::first()->price);

        // The price changes before checkout: the order must use the current price.
        $product->update(['offer_price' => 350]);
        $resp = $this->postJson('api/orders/add', $this->checkoutBody())->assertOk()->json();
        $this->assertTrue($resp['status'], (string) ($resp['message'] ?? ''));
        $order = $resp['data'];

        $this->assertEquals(350, $order['items'][0]['unit_price']);
        $this->assertEquals(350 + 60, $order['pricing']['total']);
    }

    public function test_cart_quantity_updates_are_validated_and_keep_selection(): void
    {
        $product = $this->product(['stock' => 5]);
        $this->postJson('api/cart/add', ['product_id' => $product->id, 'quantity' => 1]);
        $id = Cart::first()->id;

        $this->postJson('api/cart/update', ['cart_id' => $id, 'quantity' => 4])->assertOk()->assertJsonPath('status', true);
        $this->assertSame(4, Cart::first()->quantity);
        $this->assertTrue(Cart::first()->is_selected);
        $this->assertEquals(2000, Cart::first()->total);

        $this->postJson('api/cart/update', ['cart_id' => $id, 'quantity' => 6])->assertOk()->assertJsonPath('status', false);
        $this->postJson('api/cart/update', ['cart_id' => $id, 'quantity' => 0])->assertStatus(422);
        $this->assertSame(4, Cart::first()->quantity);
    }

    public function test_stock_is_rechecked_when_ordering_and_cart_is_kept_on_failure(): void
    {
        $product = $this->product(['stock' => 3]);
        $this->postJson('api/cart/add', ['product_id' => $product->id, 'quantity' => 3]);

        $product->update(['stock' => 1]); // someone else bought it meanwhile

        $this->postJson('api/orders/add', $this->checkoutBody())->assertOk()->assertJsonPath('status', false);
        $this->assertSame(0, Order::count());
        $this->assertSame(1, Cart::count());
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_variants_and_direct_buy(): void
    {
        $product = $this->product(['stock' => null]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-1', 'price' => 800, 'offer_price' => 700, 'stock' => 4]);

        $this->postJson('api/cart/add', ['product_id' => $product->id, 'quantity' => 1])->assertOk()->assertJsonPath('status', false); // variant required
        $this->postJson('api/cart/add', ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 5])->assertJsonPath('status', false); // over stock

        $order = $this->postJson('api/orders/direct', $this->checkoutBody() + [
            'items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2, 'price' => 1]],
        ])->assertOk()->json();

        $this->assertTrue($order['status'], $order['message'] ?? '');
        $this->assertEquals(700, $order['data']['items'][0]['unit_price']);
        $this->assertSame(2, $variant->fresh()->stock);

        // single-product shorthand from the "Buy now" button
        $again = $this->postJson('api/orders/direct', $this->checkoutBody() + ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1])->assertOk()->json();
        $this->assertTrue($again['status'], $again['message'] ?? '');
        $this->assertSame(1, $variant->fresh()->stock);
    }

    public function test_inactive_or_out_of_stock_products_cannot_be_bought(): void
    {
        $inactive = $this->product(['status' => 'inactive']);
        $soldOut = $this->product(['stock' => 0]);

        $this->postJson('api/cart/add', ['product_id' => $inactive->id, 'quantity' => 1])->assertJsonPath('status', false);
        $this->postJson('api/cart/add', ['product_id' => $soldOut->id, 'quantity' => 1])->assertJsonPath('status', false);
        $this->assertSame(0, Cart::count());
    }

    public function test_selection_endpoints_are_forgiving(): void
    {
        $product = $this->product();
        $this->postJson('api/cart/add', ['product_id' => $product->id, 'quantity' => 1]);
        $id = Cart::first()->id;

        $this->postJson('api/cart/toggle-selection', ['cart_id' => $id, 'is_selected' => false])->assertOk();
        $this->assertFalse(Cart::first()->is_selected);

        $this->postJson('api/orders/add', $this->checkoutBody())->assertOk()->assertJsonPath('status', false); // nothing selected

        $this->postJson('api/cart/select-all')->assertOk()->assertJsonPath('status', true);
        $this->postJson('api/cart/select-all')->assertOk()->assertJsonPath('status', true); // already selected is fine
        $this->postJson('api/cart/deselect-all')->assertOk()->assertJsonPath('status', true);
        $this->postJson('api/cart/deselect-all')->assertOk()->assertJsonPath('status', true);
    }

    private function seedZones(): void
    {
        \App\Models\DeliveryCharge::insert([
            ['name' => 'Inside Dhaka', 'zone' => 'inside_dhaka', 'charge' => 60, 'minimum_order' => 0, 'status' => true],
            ['name' => 'Outside Dhaka', 'zone' => 'outside_dhaka', 'charge' => 120, 'minimum_order' => 0, 'status' => true],
            ['name' => 'Free delivery', 'zone' => 'free_over', 'charge' => 0, 'minimum_order' => 2000, 'status' => true],
        ]);
    }

    private function orderIn(string $district, string $city = 'Sadar'): array
    {
        $body = $this->checkoutBody();
        $body['shipping_address']['district'] = $district;
        $body['shipping_address']['city'] = $city;
        $body['pricing']['delivery_charge'] = 1; // client value must be ignored

        return $this->postJson('api/orders/add', $body)->assertOk()->json();
    }

    public function test_delivery_zone_is_chosen_automatically_from_the_district(): void
    {
        $this->seedZones();
        $product = $this->product(['price' => 500]);

        $this->getJson('api/delivery-charges/quote?district=Dhaka&subtotal=500')->assertJsonPath('data.zone', 'inside_dhaka')->assertJsonPath('data.charge', 60);
        $this->getJson('api/delivery-charges/quote?district=Sylhet&subtotal=500')->assertJsonPath('data.zone', 'outside_dhaka')->assertJsonPath('data.charge', 120);
        $this->getJson('api/delivery-charges/quote?district=Sylhet&subtotal=2500')->assertJsonPath('data.charge', 0)->assertJsonPath('data.free_delivery', true);

        $this->postJson('api/cart/add', ['product_id' => $product->id, 'quantity' => 1]);
        $inside = $this->orderIn('Dhaka', 'Mirpur');
        $this->assertTrue($inside['status'], $inside['message'] ?? '');
        $this->assertEquals(60, $inside['data']['pricing']['delivery_charge']);
        $this->assertEquals(560, $inside['data']['pricing']['total']);
        $this->assertSame('Inside Dhaka', $inside['data']['shipping']['method']);

        $this->postJson('api/cart/add', ['product_id' => $product->id, 'quantity' => 1]);
        $outside = $this->orderIn('Chattogram', 'Hathazari');
        $this->assertEquals(120, $outside['data']['pricing']['delivery_charge']);
        $this->assertSame('Outside Dhaka', $outside['data']['shipping']['method']);

        $this->postJson('api/cart/add', ['product_id' => $product->id, 'quantity' => 4]); // 2000 -> free
        $free = $this->orderIn('Rajshahi', 'Paba');
        $this->assertEquals(0, $free['data']['pricing']['delivery_charge']);
    }

    public function test_product_orders_need_a_district_and_area(): void
    {
        $this->seedZones();
        $product = $this->product();
        $this->postJson('api/cart/add', ['product_id' => $product->id, 'quantity' => 1]);

        $body = $this->checkoutBody();
        unset($body['shipping_address']['district']);
        $this->postJson('api/orders/add', $body)->assertOk()->assertJsonPath('status', false);
        $this->assertSame(0, Order::count());
    }
}
