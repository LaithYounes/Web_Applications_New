<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Exceptions\InventoryRejectedException;
use App\Modules\Inventory\Exceptions\InventoryServiceException;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_cancel_their_pending_order_and_retry_without_releasing_stock_twice(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->order($customer);
        $product = Product::create([
            'category_id' => Category::create(['name' => 'Accessories'])->id,
            'name' => 'Keyboard',
            'price' => '50.00',
            'image_path' => 'products/keyboard.png',
        ]);
        $item = $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => '50.00',
            'quantity' => 2,
            'subtotal' => '100.00',
        ]);
        $this->mock(InventoryGrpcClient::class)
            ->shouldReceive('releaseStock')->once()->with($order->reservation_id);

        $this->actingAs($customer, 'api')
            ->postJson($this->endpoint($order))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Order cancelled successfully')
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.total_amount', '100.00')
            ->assertJsonPath('data.items.0.id', $item->id)
            ->assertJsonMissingPath('data.cancellation_pending');

        $this->postJson($this->endpoint($order))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame($order->reservation_id, $order->fresh()->reservation_id);
    }

    public function test_customer_cannot_cancel_another_customers_order_or_probe_missing_ids(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $other = $this->order(User::factory()->create(['role' => 'customer']));
        $this->mock(InventoryGrpcClient::class)->shouldNotReceive('releaseStock');

        $this->actingAs($customer, 'api');
        foreach ([$other->id, $other->id + 1, 0, 'invalid', '99999999999999999999999'] as $id) {
            $this->postJson("/api/v1/orders/{$id}/cancel")
                ->assertNotFound()
                ->assertJsonPath('success', false);
        }

        $this->assertSame('pending', $other->fresh()->status);
    }

    public function test_only_authenticated_customers_can_use_the_customer_cancellation_route(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order($admin);
        $this->mock(InventoryGrpcClient::class)->shouldNotReceive('releaseStock');

        $this->postJson($this->endpoint($order))->assertUnauthorized();
        $this->actingAs($admin, 'api')->postJson($this->endpoint($order))->assertForbidden();

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_customer_cannot_cancel_an_order_that_has_moved_past_pending(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $this->mock(InventoryGrpcClient::class)->shouldNotReceive('releaseStock');
        $this->actingAs($customer, 'api');

        foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $status) {
            $order = $this->order($customer, ['status' => $status]);
            $this->postJson($this->endpoint($order))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('status');

            $this->assertSame($status, $order->fresh()->status);
        }
    }

    public function test_order_without_a_reservation_cannot_start_cancellation(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->order($customer, ['reservation_id' => '']);
        $this->mock(InventoryGrpcClient::class)->shouldNotReceive('releaseStock');

        $this->actingAs($customer, 'api')
            ->postJson($this->endpoint($order))
            ->assertUnprocessable();

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_customer_can_retry_after_a_lost_inventory_response_and_admin_cannot_confirm_in_between(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->order($customer);
        $inventory = $this->mock(InventoryGrpcClient::class);
        $inventory->shouldReceive('releaseStock')->once()->with($order->reservation_id)
            ->andThrow(new InventoryServiceException('Simulated lost release response'));

        $this->actingAs($customer, 'api')->postJson($this->endpoint($order))
            ->assertServiceUnavailable()
            ->assertJsonPath('success', false);
        $this->assertSame(Order::CANCELLING, $order->fresh()->status);
        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.cancellation_pending', true);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertUnprocessable();
        $this->assertSame(Order::CANCELLING, $order->fresh()->status);

        $inventory->shouldReceive('releaseStock')->once()->with($order->reservation_id);
        $this->actingAs($customer, 'api')
            ->postJson($this->endpoint($order))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_admin_can_finish_an_interrupted_customer_cancellation(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->order($customer);
        $inventory = $this->mock(InventoryGrpcClient::class);
        $inventory->shouldReceive('releaseStock')->once()->with($order->reservation_id)
            ->andThrow(new InventoryRejectedException('Inventory rejected the release'));

        $this->actingAs($customer, 'api')->postJson($this->endpoint($order))
            ->assertConflict()
            ->assertJsonPath('success', false);

        $inventory->shouldReceive('releaseStock')->once()->with($order->reservation_id);
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'api')
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->actingAs($customer, 'api')->postJson($this->endpoint($order))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cancellation_can_resume_if_saving_the_final_status_fails_after_stock_release(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->order($customer);
        $this->mock(InventoryGrpcClient::class)
            ->shouldReceive('releaseStock')->twice()->with($order->reservation_id);
        $failed = false;
        Order::updated(function (Order $updated) use (&$failed): void {
            if ($updated->status === 'cancelled' && !$failed) {
                $failed = true;
                throw new RuntimeException('Simulated failure after releasing stock');
            }
        });

        $this->actingAs($customer, 'api')->postJson($this->endpoint($order))->assertStatus(500);
        $this->assertSame(Order::CANCELLING, $order->fresh()->status);

        $this->postJson($this->endpoint($order))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    private function order(User $user, array $attributes = []): Order
    {
        return Order::create($attributes + [
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => '100.00',
            'reservation_id' => (string) str()->uuid(),
        ]);
    }

    private function endpoint(Order $order): string
    {
        return "/api/v1/orders/{$order->id}/cancel";
    }
}
