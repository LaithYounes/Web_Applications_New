<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Exceptions\InventoryRejectedException;
use App\Modules\Inventory\Exceptions\InventoryServiceException;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\PendingCheckout;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CheckoutRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_lost_reservation_reply_keeps_durable_intent_and_retries_the_same_id(): void
    {
        [$user, $cart, $product] = $this->basket();
        $calls = [];
        $inventory = $this->mock(InventoryGrpcClient::class);
        $inventory->shouldReceive('reserveStock')->twice()->andReturnUsing(function ($id, $items) use (&$calls) {
            $this->assertDatabaseHas('pending_checkouts', ['reservation_id' => $id]);
            $calls[] = [$id, $items];
            if (count($calls) === 1) {
                throw new InventoryServiceException('Lost successful reply');
            }
        });
        $inventory->shouldNotReceive('releaseStock');

        $this->actingAs($user, 'api')->postJson('/api/v1/orders')->assertServiceUnavailable();
        $this->assertDatabaseCount('pending_checkouts', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, $cart->items()->count());
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.checkout_pending', true);

        // Neither catalog changes nor a deleted product may change the persisted purchase.
        $product->update(['price' => '99.99', 'name' => 'Changed']);
        $product->delete();
        $this->postJson('/api/v1/orders')->assertCreated()
            ->assertJsonPath('data.total_amount', '12.50')
            ->assertJsonPath('data.items.0.product_name', 'Original');
        $this->assertSame($calls[0], $calls[1]);
        $this->assertDatabaseCount('pending_checkouts', 0);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('cart_items', 0);

        $order = app(OrderService::class)->finishCheckout($calls[0][0]);
        $this->assertSame(Order::firstOrFail()->id, $order->id);
        $this->assertCount(1, $order->items);
    }

    public function test_recovery_worker_finishes_after_local_save_fails_without_a_customer_retry(): void
    {
        [$user] = $this->basket();
        $ids = [];
        $this->mock(InventoryGrpcClient::class)->shouldReceive('reserveStock')->twice()
            ->andReturnUsing(function ($id) use (&$ids) {
                $ids[] = $id;
            });
        $failed = false;
        Order::created(function () use (&$failed) {
            if (! $failed) {
                $failed = true;
                throw new RuntimeException('Process failed after reserving inventory');
            }
        });

        $this->actingAs($user, 'api')->postJson('/api/v1/orders')->assertStatus(500);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('pending_checkouts', 1);
        $this->artisan('orders:recover-checkouts', ['--min-age' => 0])->assertSuccessful();
        $this->assertSame($ids[0], $ids[1]);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('pending_checkouts', 0);
        $this->assertDatabaseCount('cart_items', 0);
        $this->artisan('orders:recover-checkouts', ['--min-age' => 0])->assertSuccessful();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_cart_writes_are_blocked_until_an_ambiguous_checkout_is_resolved(): void
    {
        [$user, $cart, $product] = $this->basket();
        $this->mock(InventoryGrpcClient::class)->shouldReceive('reserveStock')->once()
            ->andThrow(new InventoryServiceException('Unavailable'));
        $this->actingAs($user, 'api')->postJson('/api/v1/orders')->assertServiceUnavailable();
        $item = $cart->items()->firstOrFail();
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertConflict();
        $this->putJson("/api/v1/cart/items/{$item->id}", ['quantity' => 2])->assertConflict();
        $this->deleteJson("/api/v1/cart/items/{$item->id}")->assertConflict();
        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 1]);
    }

    public function test_definitive_rejection_unlocks_the_cart_and_a_new_attempt_gets_a_new_id(): void
    {
        [$user, $cart] = $this->basket();
        $ids = [];
        $this->mock(InventoryGrpcClient::class)->shouldReceive('reserveStock')->twice()
            ->andReturnUsing(function ($id) use (&$ids) {
                $ids[] = $id;
                if (count($ids) === 1) {
                    throw new InventoryRejectedException('Insufficient stock');
                }
            });
        $this->actingAs($user, 'api')->postJson('/api/v1/orders')->assertConflict();
        $this->assertDatabaseCount('pending_checkouts', 0);
        $item = $cart->items()->firstOrFail();
        $this->putJson("/api/v1/cart/items/{$item->id}", ['quantity' => 2])->assertOk();
        $this->postJson('/api/v1/orders')->assertCreated()->assertJsonPath('data.items.0.quantity', 2);
        $this->assertNotSame($ids[0], $ids[1]);
    }

    public function test_worker_preserves_pending_intent_during_an_outage_and_recovers_later(): void
    {
        [$user] = $this->basket();
        $inventory = $this->mock(InventoryGrpcClient::class);
        $inventory->shouldReceive('reserveStock')->twice()->andThrow(new InventoryServiceException('Unavailable'));
        $this->actingAs($user, 'api')->postJson('/api/v1/orders')->assertServiceUnavailable();
        $id = PendingCheckout::firstOrFail()->reservation_id;
        $this->artisan('orders:recover-checkouts', ['--min-age' => 0])->assertFailed();
        $this->assertDatabaseHas('pending_checkouts', ['reservation_id' => $id]);
        $inventory->shouldReceive('reserveStock')->once()->with($id, \Mockery::type('array'));
        $this->artisan('orders:recover-checkouts', ['--min-age' => 0])->assertSuccessful();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_scheduled_recovery_backs_off_between_failures(): void
    {
        [$user] = $this->basket();
        $inventory = $this->mock(InventoryGrpcClient::class);
        $inventory->shouldReceive('reserveStock')->twice()->andThrow(new InventoryServiceException('Unavailable'));
        $this->actingAs($user, 'api')->postJson('/api/v1/orders')->assertServiceUnavailable();
        $this->artisan('orders:recover-checkouts')->assertSuccessful();

        $this->travel(61)->seconds();
        $this->artisan('orders:recover-checkouts')->assertFailed();
        $this->artisan('orders:recover-checkouts')->assertSuccessful();
        $this->assertDatabaseCount('pending_checkouts', 1);

        $inventory->shouldReceive('reserveStock')->once();
        $this->travel(61)->seconds();
        $this->artisan('orders:recover-checkouts')->assertSuccessful();
        $this->assertDatabaseCount('pending_checkouts', 0);
        $this->assertDatabaseCount('orders', 1);
    }

    private function basket(): array
    {
        $user = User::factory()->create(['role' => 'customer']);
        $category = Category::create(['name' => 'Test']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Original', 'price' => '12.50', 'image_path' => 'test.png']);
        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        return [$user, $cart, $product];
    }
}
