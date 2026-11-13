<?php

namespace Tests\Feature\Performance;

use App\Models\User;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class OrderPrimaryReadTest extends TestCase
{
    public function test_order_reads_and_relations_use_primary_outside_a_transaction(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $user = User::factory()->create();
        $product = Product::create([
            'category_id' => Category::create(['name' => 'Primary read test'])->id,
            'name' => 'Snapshot product',
            'price' => '12.50',
            'image_path' => 'test.png',
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => '12.50',
            'reservation_id' => 'primary-read-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'product_name' => 'Snapshot product',
            'unit_price' => '12.50',
            'quantity' => 1,
            'subtotal' => '12.50',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_status_histories')->insert([
            'order_id' => $orderId,
            'to_status' => 'pending',
            'actor_type' => 'system',
            'changed_at' => now(),
        ]);

        $connection = DB::connection();
        $this->assertSame(0, $connection->transactionLevel());
        $connection->forgetRecordModificationState();
        $connection->setReadPdo(fn () => throw new RuntimeException('Replica accessed'));
        config(['perf.orders_use_primary' => true]);
        $service = app(OrderService::class);

        foreach ([$service->getOrder($orderId), $service->getUserOrder($user, $orderId)] as $order) {
            $this->assertSame($orderId, $order->id);
            $this->assertCount(1, $order->items);
            $this->assertCount(1, $order->statusHistory);
        }
        $this->assertCount(1, $service->getUserOrders($user)->first()->items);
        $adminOrder = $service->getAllOrders()->first();
        $this->assertCount(1, $adminOrder->items);
        $this->assertSame($user->id, $adminOrder->user->id);

        config(['perf.orders_use_primary' => false]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Replica accessed');
        $service->getOrder($orderId);
    }
}
