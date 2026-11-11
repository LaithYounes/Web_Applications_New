<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Exceptions\InventoryServiceException;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class OrderStatusHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_and_admin_lifecycle_record_ordered_history_and_authenticated_actors(): void
    {
        $customer = $this->basket();
        $admin = User::factory()->create(['role' => 'admin']);
        $inventory = $this->mock(InventoryGrpcClient::class);
        $inventory->shouldReceive('reserveStock')->once();
        $inventory->shouldReceive('commitReservation')->once();

        $response = $this->actingAs($customer, 'api')->postJson('/api/v1/orders')
            ->assertCreated()
            ->assertJsonPath('data.status_history.0.from_status', null)
            ->assertJsonPath('data.status_history.0.to_status', 'pending')
            ->assertJsonPath('data.status_history.0.changed_by', $customer->id)
            ->assertJsonPath('data.status_history.0.actor_type', 'customer');
        $id = $response->json('data.id');

        $this->actingAs($admin, 'api');
        foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $status) {
            $this->patchJson("/api/v1/orders/{$id}/status", [
                'status' => $status,
                'changed_by' => $customer->id,
                'actor_type' => 'customer',
            ])->assertOk();
        }

        $changes = Order::findOrFail($id)->statusHistory;
        $this->assertSame([null, 'pending', 'confirmed', 'processing', 'shipped'], $changes->pluck('from_status')->all());
        $this->assertSame(['pending', 'confirmed', 'processing', 'shipped', 'delivered'], $changes->pluck('to_status')->all());
        foreach ($changes->skip(1) as $change) {
            $this->assertSame($admin->id, $change->changed_by);
            $this->assertSame('admin', $change->actor_type);
            $this->assertNotNull($change->changed_at);
        }
        $this->getJson("/api/v1/orders/{$id}")->assertOk()->assertJsonCount(5, 'data.status_history');
        $this->actingAs($customer, 'api')->getJson("/api/v1/orders/{$id}")
            ->assertOk()->assertJsonCount(5, 'data.status_history');
    }

    public function test_interrupted_customer_cancellation_records_only_persisted_transitions_once(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->order($customer);
        $inventory = $this->mock(InventoryGrpcClient::class);
        $inventory->shouldReceive('releaseStock')->once()->andThrow(new InventoryServiceException('Lost reply'));

        $this->actingAs($customer, 'api')->postJson("/api/v1/orders/{$order->id}/cancel")->assertServiceUnavailable();
        $this->assertSame(['pending', Order::CANCELLING], $order->statusHistory()->pluck('to_status')->all());

        $inventory->shouldReceive('releaseStock')->once();
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk()->assertJsonCount(3, 'data.status_history');
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk()->assertJsonCount(3, 'data.status_history');
        $this->assertSame(['pending', Order::CANCELLING, 'cancelled'], $order->statusHistory()->pluck('to_status')->all());
        $this->assertSame([null, 'pending', Order::CANCELLING], $order->statusHistory()->pluck('from_status')->all());
        $this->assertSame([$customer->id, $customer->id], $order->statusHistory()->whereNotNull('from_status')->pluck('changed_by')->all());
    }

    public function test_invalid_unauthorized_and_failed_inventory_transitions_do_not_create_history(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order($customer);
        $this->mock(InventoryGrpcClient::class)->shouldReceive('releaseStock')->once()->andThrow(new InventoryServiceException('Unavailable'));
        $this->actingAs($customer, 'api')->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed'])->assertForbidden();
        $this->actingAs($admin, 'api')->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'shipped'])->assertUnprocessable();
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'cancelled'])->assertServiceUnavailable();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(1, $order->statusHistory()->count());

        $this->actingAs(User::factory()->create(['role' => 'customer']), 'api')
            ->getJson("/api/v1/orders/{$order->id}")->assertNotFound();
    }

    public function test_a_failed_history_insert_rolls_back_status_even_for_a_direct_model_save(): void
    {
        $order = $this->order(User::factory()->create());
        OrderStatusHistory::creating(fn () => throw new RuntimeException('History unavailable'));
        try {
            $order->update(['status' => 'confirmed']);
            $this->fail('Expected history write to fail');
        } catch (RuntimeException $exception) {
            $this->assertSame('History unavailable', $exception->getMessage());
        }
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(1, $order->statusHistory()->count());
    }

    public function test_outer_rollback_removes_history_and_unrelated_saves_add_no_entries(): void
    {
        $order = $this->order(User::factory()->create());
        $order->save();
        $order->update(['total_amount' => '20.00']);
        $this->assertSame(1, $order->statusHistory()->count());

        try {
            DB::transaction(function () use ($order) {
                $order->update(['status' => 'confirmed']);
                throw new RuntimeException('Transaction failed');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(1, $order->statusHistory()->count());
    }

    public function test_automatic_checkout_recovery_is_attributed_to_system_and_does_not_duplicate_history(): void
    {
        $customer = $this->basket();
        $inventory = $this->mock(InventoryGrpcClient::class);
        $inventory->shouldReceive('reserveStock')->once()->andThrow(new InventoryServiceException('Lost reply'));
        $this->actingAs($customer, 'api')->postJson('/api/v1/orders')->assertServiceUnavailable();
        $this->assertDatabaseCount('order_status_histories', 0);
        $inventory->shouldReceive('reserveStock')->once();
        $this->artisan('orders:recover-checkouts', ['--min-age' => 0])->assertSuccessful();
        $order = Order::firstOrFail();
        $this->assertSame(1, $order->statusHistory()->count());
        $change = $order->statusHistory()->firstOrFail();
        $this->assertNull($change->changed_by);
        $this->assertSame('system', $change->actor_type);
        app(OrderService::class)->finishCheckout($order->reservation_id);
        $this->assertSame(1, $order->statusHistory()->count());
    }

    public function test_creation_is_rolled_back_if_its_initial_history_cannot_be_saved(): void
    {
        OrderStatusHistory::creating(fn () => throw new RuntimeException('History unavailable'));
        try {
            $this->order(User::factory()->create());
            $this->fail('Expected history write to fail');
        } catch (RuntimeException $exception) {
            $this->assertSame('History unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_status_histories', 0);
    }

    private function order(User $customer): Order
    {
        return Order::create(['user_id' => $customer->id, 'total_amount' => '12.50', 'reservation_id' => (string) str()->uuid()]);
    }

    private function basket(): User
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $product = Product::create([
            'category_id' => Category::create(['name' => 'Test'])->id,
            'name' => 'Test', 'price' => '12.50', 'image_path' => 'test.png',
        ]);
        $cart = Cart::create(['user_id' => $customer->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        return $customer;
    }
}
