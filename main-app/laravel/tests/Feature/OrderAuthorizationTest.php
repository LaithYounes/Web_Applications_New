<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function createOrder(User $user): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 100.00,
            'reservation_id' => (string) str()->uuid(),
        ]);
    }

    public function test_customer_can_view_only_their_own_orders(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
        ]);

        $anotherCustomer = User::factory()->create([
            'role' => 'customer',
        ]);

        $ownOrder = $this->createOrder($customer);
        $this->createOrder($anotherCustomer);

        $response = $this->actingAs($customer, 'api')
            ->getJson('/api/v1/orders');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownOrder->id);
    }

    public function test_customer_cannot_view_another_customers_order(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
        ]);

        $anotherCustomer = User::factory()->create([
            'role' => 'customer',
        ]);

        $otherOrder = $this->createOrder($anotherCustomer);

        $response = $this->actingAs($customer, 'api')
            ->getJson("/api/v1/orders/{$otherOrder->id}");

        $response->assertNotFound();
    }

    public function test_customer_cannot_change_order_status(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
        ]);

        $order = $this->createOrder($customer);

        $response = $this->actingAs($customer, 'api')
            ->patchJson("/api/v1/orders/{$order->id}/status", [
                'status' => 'confirmed',
            ]);

        $response->assertForbidden();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
        ]);
    }

    public function test_admin_can_view_all_orders(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $customerOne = User::factory()->create([
            'role' => 'customer',
        ]);

        $customerTwo = User::factory()->create([
            'role' => 'customer',
        ]);

        $this->createOrder($customerOne);
        $this->createOrder($customerTwo);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/orders');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_change_order_status(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $customer = User::factory()->create([
            'role' => 'customer',
        ]);

        $order = $this->createOrder($customer);

        $response = $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/orders/{$order->id}/status", [
                'status' => 'confirmed',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_guest_cannot_access_orders(): void
    {
        $this->getJson('/api/v1/orders')
            ->assertUnauthorized();

        $this->postJson('/api/v1/cart/items', [
            'product_id' => 1,
            'quantity' => 1,
        ])->assertUnauthorized();
    }


    public function test_api_responses_include_security_headers(): void
{
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-XSS-Protection', '0');
}
}
