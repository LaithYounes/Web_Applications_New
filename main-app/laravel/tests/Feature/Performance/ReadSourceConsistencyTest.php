<?php

namespace Tests\Feature\Performance;

use App\Models\User;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\PendingCheckout;
use App\Modules\Orders\Resources\CartResource;
use App\Modules\Orders\Resources\OrderResource;
use App\Modules\Orders\Services\CartService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadSourceConsistencyTest extends TestCase
{
    private User $user;
    private Order $order;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config(['database.connections.replica_test' => config('database.connections.sqlite')]);
        $this->artisan('migrate', ['--database' => 'replica_test', '--force' => true])->assertSuccessful();
        $this->user = User::factory()->create();
        $this->product = Product::create([
            'category_id' => Category::create(['name' => 'Primary'])->id,
            'name' => 'Current', 'price' => '12.50', 'image_path' => 'test.png',
        ]);
        $this->order = Order::create(['user_id' => $this->user->id, 'total_amount' => '12.50', 'reservation_id' => 'read-source']);
        $this->order->items()->create(['product_id' => $this->product->id, 'product_name' => 'Snapshot', 'unit_price' => '12.50', 'quantity' => 1, 'subtotal' => '12.50']);
        DB::connection()->setReadPdo(DB::connection('replica_test')->getPdo());
        DB::connection()->forgetRecordModificationState();
        config(['perf.orders_use_primary' => true, 'database.connections.sqlite.sticky' => false]);
    }

    private function independentRequest(): void
    {
        DB::connection()->forgetRecordModificationState();
        auth('api')->forgetUser();
    }

    public function test_independent_customer_admin_lists_details_and_ownership(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create();
        foreach ([$this->user, $admin] as $actor) {
            foreach (['/api/v1/orders', '/api/v1/orders/'.$this->order->id] as $path) {
                $this->independentRequest();
                $response = $this->actingAs($actor, 'api')->getJson($path)->assertOk();
                $prefix = str_ends_with($path, 'orders') ? 'data.0' : 'data';
                $response->assertJsonPath($prefix.'.id', $this->order->id)->assertJsonCount(1, $prefix.'.items');
            }
        }
        $this->actingAs($other, 'api')->getJson('/api/v1/orders/'.$this->order->id)->assertNotFound();
        $this->getJson('/api/v1/orders')->assertJsonCount(0, 'data');
        $this->actingAs($admin, 'api')->getJson('/api/v1/orders/999999')->assertNotFound();
    }

    public function test_parent_on_replica_but_items_and_new_history_only_on_primary(): void
    {
        $replica = DB::connection('replica_test');
        $replica->table('users')->insert($this->user->getAttributes());
        $replica->table('orders')->insert($this->order->getAttributes());
        $this->order->update(['status' => 'confirmed']);
        $this->independentRequest();
        $this->actingAs($this->user, 'api')->getJson('/api/v1/orders/'.$this->order->id)
            ->assertJsonPath('data.status', 'confirmed')->assertJsonCount(1, 'data.items')
            ->assertJsonCount(2, 'data.status_history')->assertJsonPath('data.status_history.1.to_status', 'confirmed');
        config(['perf.orders_use_primary' => false]);
        $this->independentRequest();
        $lagged = app(OrderService::class)->getOrder($this->order->id);
        $this->assertSame('pending', $lagged->status);
        $this->assertCount(0, $lagged->items);
        $this->assertCount(0, $lagged->statusHistory);
        // All resource fields were materialized; serializing later issues no query.
        DB::connection()->enableQueryLog();
        (new OrderResource($lagged))->resolve(request());
        $this->assertCount(0, DB::connection()->getQueryLog());
    }

    public function test_flag_transaction_and_reused_worker_do_not_leak_primary_policy(): void
    {
        $service = app(OrderService::class);
        $this->assertSame($this->order->id, $service->getOrder($this->order->id)->id);
        $this->assertSame(0, DB::table('orders')->count(), 'Eligible read must still use replica.');
        config(['perf.orders_use_primary' => false]);
        DB::transaction(function () use ($service) {
            $this->assertCount(1, $service->getOrder($this->order->id)->items);
        });
        DB::connection()->forgetRecordModificationState();
        $this->assertSame(0, DB::table('orders')->count());
        $this->expectException(ModelNotFoundException::class);
        $service->getOrder($this->order->id);
    }

    public function test_eager_loading_multiple_pages_has_constant_query_count(): void
    {
        for ($i = 0; $i < 21; $i++) {
            Order::create(['user_id' => $this->user->id, 'total_amount' => '0.00', 'reservation_id' => 'page-'.$i]);
        }
        DB::connection()->forgetRecordModificationState();
        DB::connection()->enableQueryLog();
        $orders = app(OrderService::class)->getAllOrders();
        OrderResource::collection($orders)->resolve(request());
        $this->assertCount(4, DB::connection()->getQueryLog(), 'count + orders + users + items, independent of page size');
        $this->assertSame(22, $orders->total());
        $this->actingAs($this->user, 'api')->getJson('/api/v1/orders?page=3')->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 22);
    }

    public function test_cart_graph_and_pending_are_resolved_before_resource_serialization(): void
    {
        $cart = Cart::create(['user_id' => $this->user->id]);
        $cart->items()->create(['product_id' => $this->product->id, 'quantity' => 1]);
        PendingCheckout::create(['reservation_id' => 'pending-read', 'cart_id' => $cart->id, 'order_items' => [], 'total_amount' => '0.00']);
        DB::connection()->forgetRecordModificationState();
        $loaded = app(CartService::class)->getCart($this->user);
        DB::connection()->enableQueryLog();
        $resource = (new CartResource($loaded))->resolve(request());
        $this->assertTrue($resource['checkout_pending']);
        $this->assertCount(1, $resource['items']);
        $this->assertCount(0, DB::connection()->getQueryLog());
    }

    public function test_pending_recovery_decision_reads_primary_even_when_flag_is_disabled(): void
    {
        config(['perf.orders_use_primary' => false]);
        $cart = Cart::create(['user_id' => $this->user->id]);
        PendingCheckout::create(['reservation_id' => 'recover-read', 'cart_id' => $cart->id, 'order_items' => [[
            'product_id' => $this->product->id, 'product_name' => 'Snapshot', 'unit_price' => '12.50', 'quantity' => 1, 'subtotal' => '12.50',
        ]], 'total_amount' => '12.50']);
        $this->mock(InventoryGrpcClient::class)->shouldReceive('reserveStock')->once()->with('recover-read', [['product_id' => $this->product->id, 'quantity' => 1]])->andReturnNull();
        DB::connection()->forgetRecordModificationState();
        $order = app(OrderService::class)->finishCheckout('recover-read');
        $this->assertCount(1, $order->items);
        DB::connection()->forgetRecordModificationState();
        $this->assertSame($order->id, app(OrderService::class)->finishCheckout('recover-read')->id);
    }
}
