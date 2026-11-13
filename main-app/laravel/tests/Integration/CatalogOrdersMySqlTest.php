<?php

namespace Tests\Integration;

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
use App\Modules\Products\Services\CategoryService;
use App\Modules\Products\Services\ProductService;
use App\Support\Performance\CatalogCache;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\TestCase;
use Tests\Support\Round2Environment;

/** Real MySQL replication; deliberately outside the default SQLite suite. */
class CatalogOrdersMySqlTest extends TestCase
{
    private $app;
    private array $manifest;
    private static bool $migrated = false;

    protected function setUp(): void
    {
        [$this->app, $this->manifest] = Round2Environment::boot();
        if (! self::$migrated) {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            self::$migrated = true;
        }
        Round2Environment::synchronize($this->manifest);
        $this->app->instance('request', Request::create('/api/v1/products'));
    }

    protected function tearDown(): void
    {
        Round2Environment::synchronize($this->manifest);
        Mockery::close();
        DB::disconnect();
        $this->app->flush();
        \Illuminate\Foundation\Bootstrap\HandleExceptions::flushState($this);
    }

    private function pause(): void
    {
        Round2Environment::synchronize($this->manifest);
        Round2Environment::replicaControl($this->manifest)->exec('STOP REPLICA SQL_THREAD');
    }

    private function fixtures(): array
    {
        $user = User::factory()->create();
        $product = Product::create([
            'category_id' => Category::create(['name' => 'Round2 '.str()->uuid()])->id,
            'name' => 'Primary catalog', 'price' => '19.00', 'image_path' => 'test.png',
        ]);
        $order = Order::create(['user_id' => $user->id, 'total_amount' => '19.00', 'reservation_id' => (string) str()->uuid()]);

        return [$user, $product, $order];
    }

    private function item(Order $order, Product $product): void
    {
        $order->items()->create(['product_id' => $product->id, 'product_name' => 'Snapshot', 'unit_price' => '19.00', 'quantity' => 1, 'subtotal' => '19.00']);
    }

    private function http(User $user, string $method, string $uri, array $body = []): array
    {
        // New HTTP request, no sticky state inherited from the preceding write.
        DB::connection()->forgetRecordModificationState();
        auth('api')->forgetUser();
        auth('api')->setUser($user);
        $request = Request::create($uri, $method, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], json_encode($body));
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return [$response->getStatusCode(), json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function test_new_parent_absent_on_real_replica_customer_and_admin_http_reads(): void
    {
        $this->pause();
        [$user, $product, $order] = $this->fixtures();
        $this->item($order, $product);
        $admin = User::factory()->create(['role' => 'admin']);
        DB::connection()->forgetRecordModificationState();
        $this->assertSame(0, DB::table('orders')->where('id', $order->id)->count());
        foreach ([$user, $admin] as $actor) {
            [$status, $json] = $this->http($actor, 'GET', '/api/v1/orders/'.$order->id);
            $this->assertSame(200, $status);
            $this->assertSame($order->id, $json['data']['id']);
            $this->assertCount(1, $json['data']['items']);
            $this->assertCount(1, $json['data']['status_history']);
            [$status, $json] = $this->http($actor, 'GET', '/api/v1/orders');
            $this->assertSame(200, $status);
            $this->assertContains($order->id, array_column($json['data'], 'id'));
        }
        $other = User::factory()->create();
        $this->assertSame(404, $this->http($other, 'GET', '/api/v1/orders/'.$order->id)[0]);
        $this->assertSame([], $this->http($other, 'GET', '/api/v1/orders')[1]['data']);
        $this->assertSame(404, $this->http($admin, 'GET', '/api/v1/orders/999999')[0]);
    }

    public function test_existing_parent_lagged_items_status_history_flag_transactions_and_worker_reuse(): void
    {
        [$user, $product, $order] = $this->fixtures();
        $this->pause();
        $this->item($order, $product);
        $order->update(['status' => 'confirmed']);
        DB::connection()->forgetRecordModificationState();
        $service = app(OrderService::class);
        foreach ([$service->getOrder($order->id), $service->getUserOrder($user, $order->id), $service->getUserOrders($user)->first()] as $current) {
            $this->assertSame('confirmed', $current->status);
            $this->assertCount(1, $current->items);
        }
        $current = $service->getOrder($order->id);
        $this->assertCount(2, $current->statusHistory);
        DB::connection()->enableQueryLog();
        (new OrderResource($current))->resolve(request());
        $this->assertCount(0, DB::connection()->getQueryLog(), 'No lazy resource query.');
        DB::connection()->disableQueryLog();
        config(['perf.orders_use_primary' => false]);
        $stale = $service->getOrder($order->id);
        $this->assertSame('pending', $stale->status);
        $this->assertCount(0, $stale->items);
        $this->assertCount(1, $stale->statusHistory);
        DB::transaction(function () use ($service, $order) {
            $this->assertCount(1, $service->getOrder($order->id)->items);
        });
        $this->assertSame('pending', DB::table('orders')->where('id', $order->id)->value('status'), 'Worker must retain replica eligibility.');
        config(['perf.orders_use_primary' => true]);
        for ($i = 0; $i < 20; $i++) {
            Order::create(['user_id' => $user->id, 'total_amount' => '0.00', 'reservation_id' => (string) str()->uuid()]);
        }
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        OrderResource::collection($service->getAllOrders())->resolve(request());
        $this->assertCount(4, DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
        [$status, $json] = $this->http($user, 'GET', '/api/v1/orders?page=3');
        $this->assertSame(200, $status);
        $this->assertCount(1, $json['data']);
        $this->assertSame(21, $json['meta']['total']);
    }

    public function test_http_checkout_status_change_and_separate_gets_under_lag(): void
    {
        [$user, $product] = $this->fixtures();
        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->pause();
        $inventory = Mockery::mock(InventoryGrpcClient::class);
        $inventory->shouldReceive('reserveStock')->once()->andReturnNull();
        $this->app->instance(InventoryGrpcClient::class, $inventory);
        [$status, $created] = $this->http($user, 'POST', '/api/v1/orders');
        $this->assertSame(201, $status);
        $id = $created['data']['id'];
        [$status, $updated] = $this->http($admin, 'PATCH', '/api/v1/orders/'.$id.'/status', ['status' => 'confirmed']);
        $this->assertSame(200, $status);
        $this->assertSame('confirmed', $updated['data']['status']);
        foreach ([$user, $admin] as $actor) {
            [$status, $json] = $this->http($actor, 'GET', '/api/v1/orders/'.$id);
            $this->assertSame(200, $status);
            $this->assertSame('confirmed', $json['data']['status']);
            $this->assertCount(1, $json['data']['items']);
            $this->assertCount(2, $json['data']['status_history']);
        }
        [$status, $json] = $this->http($user, 'GET', '/api/v1/cart');
        $this->assertSame(200, $status);
        $this->assertSame([], $json['data']['items']);
        $this->assertFalse($json['data']['checkout_pending']);
    }

    public function test_cart_pending_and_recovery_command_with_replica_lag(): void
    {
        [$user, $product] = $this->fixtures();
        $cart = Cart::create(['user_id' => $user->id]);
        $this->pause();
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $reservation = (string) str()->uuid();
        PendingCheckout::create(['reservation_id' => $reservation, 'cart_id' => $cart->id, 'order_items' => [[
            'product_id' => $product->id, 'product_name' => 'Snapshot', 'unit_price' => '19.00', 'quantity' => 1, 'subtotal' => '19.00',
        ]], 'total_amount' => '19.00']);
        DB::connection()->forgetRecordModificationState();
        $loaded = app(CartService::class)->getCart($user);
        $resource = (new CartResource($loaded))->resolve(request());
        $this->assertTrue($resource['checkout_pending']);
        $this->assertCount(1, $resource['items']);
        $inventory = Mockery::mock(InventoryGrpcClient::class);
        $inventory->shouldReceive('reserveStock')->once()->with($reservation, [['product_id' => $product->id, 'quantity' => 1]])->andReturnNull();
        $this->app->instance(InventoryGrpcClient::class, $inventory);
        config(['perf.orders_use_primary' => false]);
        DB::connection()->forgetRecordModificationState();
        $this->assertSame(0, Artisan::call('orders:recover-checkouts', ['--min-age' => 0]));
        $order = app(OrderService::class)->finishCheckout($reservation);
        $this->assertCount(1, $order->items);
        $this->assertFalse(PendingCheckout::useWritePdo()->whereKey($reservation)->exists());
    }

    public function test_catalog_fill_on_primary_and_atomic_revision_with_redis_outage_recovery(): void
    {
        [, $product] = $this->fixtures();
        $this->pause();
        $service = app(ProductService::class);
        $cache = app(CatalogCache::class);
        Cache::put('round2-probe', 'ok', 30);
        $this->assertSame('ok', Cache::get('round2-probe'));
        $service->getAll($product->category_id);
        $this->assertIsArray(Cache::get($cache->productsKey($product->category_id, 12, 1)));
        $hits = $cache->hits;
        $service->getAll($product->category_id);
        $this->assertSame($hits + 1, $cache->hits);
        // Keep Redis's old payload, simulate complete read/write outage in Laravel.
        $repository = Cache::getFacadeRoot();
        Cache::swap(Mockery::mock()->shouldReceive('get', 'put')->andThrow(new \RuntimeException('Injected cache outage'))->getMock());
        $service->update($product->id, ['name' => 'Committed during outage', 'price' => '23.00']);
        $this->assertSame('Committed during outage', $service->getAll($product->category_id)->first()->name);
        Cache::swap($repository);
        $this->assertSame('Committed during outage', $service->getAll($product->category_id)->first()->name);
        $this->assertSame('Primary catalog', DB::table('products')->where('id', $product->id)->value('name'), 'Real replica is still lagged.');
        $this->assertSame(1, app(CategoryService::class)->getAll()->firstWhere('id', $product->category_id)->products_count);
        $version = $cache->version();
        DB::beginTransaction();
        $service->update($product->id, ['name' => 'Rollback']);
        DB::rollBack();
        $this->assertSame($version, $cache->version());
        $this->assertSame('Committed during outage', $service->getAll($product->category_id)->first()->name);
    }

    private function workers(array $actions, Product $product): void
    {
        $barrier = bin2hex(random_bytes(8));
        $base = dirname(getenv('ROUND2_TEST_MANIFEST')).'/'.$barrier;
        $workers = [];
        try {
            foreach ($actions as $id => $action) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, dirname(__DIR__).'/Support/round2-worker.php', $action, $barrier, (string) $id, (string) $product->id, (string) $product->category_id],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $workers[$id] = [$process, $pipes];
            }
            $deadline = microtime(true) + 25;
            do {
                $ready = count(glob($base.'.ready.*')) === count($actions);
                if (microtime(true) > $deadline) {
                    $this->fail('Workers did not reach barrier.');
                }
                usleep(5000);
            } while (! $ready);
            file_put_contents($base.'.go', 'start');
            foreach ($workers as $id => [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                unset($workers[$id]);
                $this->assertSame(0, $exit, $error);
                $this->assertTrue(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['ok']);
            }
        } finally {
            foreach ($workers as [$process, $pipes]) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
            foreach (glob($base.'.*') as $file) {
                unlink($file);
            }
        }
    }

    public function test_concurrent_invalidations_never_lose_a_generation_and_reads_race_mutation(): void
    {
        [, $product] = $this->fixtures();
        $cache = app(CatalogCache::class);
        $before = $cache->version();
        $this->workers(['invalidate', 'invalidate', 'invalidate'], $product);
        $this->assertSame($before + 60, $cache->version());
        app(ProductService::class)->getAll($product->category_id);
        $this->workers(['write', 'read', 'read'], $product);
        $this->assertSame('Concurrent 19', app(ProductService::class)->getAll($product->category_id)->first()->name);
        $this->assertSame($before + 80, $cache->version());
    }

    public function test_reader_filling_old_namespace_cannot_poison_committed_generation(): void
    {
        [, $product] = $this->fixtures();
        $service = app(ProductService::class);
        $cache = app(CatalogCache::class);
        $oldKey = $cache->productsKey($product->category_id, 12, 1);
        $cache->productsList($product->category_id, 12, 1, function () use ($service, $product) {
            // Deterministic interleaving: reader chose its key, another committed
            // writer advances the durable revision, then reader fills the old key.
            $config = config('database.connections.round2');
            config(['database.connections.round2_writer' => $config]);
            $writer = DB::connection('round2_writer');
            $writer->transaction(function () use ($writer, $product) {
                $writer->table('products')->where('id', $product->id)->update(['name' => 'Racing commit']);
                $writer->table('catalog_cache_state')->where('id', 1)->increment('generation');
            });
            config(['perf.catalog_cache_enabled' => false]);
            try {
                return $service->getAll($product->category_id);
            } finally {
                config(['perf.catalog_cache_enabled' => true]);
            }
        });
        $this->assertIsArray(Cache::get($oldKey));
        $this->assertNotSame($oldKey, $cache->productsKey($product->category_id, 12, 1));
        $this->assertSame('Racing commit', $service->getAll($product->category_id)->first()->name);
    }

    public function test_generation_and_data_are_invisible_to_other_connections_until_outer_commit(): void
    {
        [, $product] = $this->fixtures();
        $service = app(ProductService::class);
        $cache = app(CatalogCache::class);
        $service->getAll($product->category_id);
        $oldKey = $cache->productsKey($product->category_id, 12, 1);
        $oldPayload = Cache::get($oldKey);
        $before = $cache->version();
        config(['database.connections.round2_observer' => config('database.connections.round2')]);
        $observer = DB::connection('round2_observer');
        DB::beginTransaction();
        try {
            $service->update($product->id, ['name' => 'After outer commit']);
            $this->assertSame($before, (int) $observer->table('catalog_cache_state')->useWritePdo()->where('id', 1)->value('generation'));
            $this->assertSame('Primary catalog', $observer->table('products')->useWritePdo()->where('id', $product->id)->value('name'));
            $this->assertSame($oldPayload, Cache::get($oldKey));
            $this->assertSame('After outer commit', $service->getAll($product->category_id)->first()->name);
            $this->assertNull($cache->lastKey, 'Uncommitted reads must bypass shared cache.');
            DB::commit();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $this->assertSame($before + 1, (int) $observer->table('catalog_cache_state')->useWritePdo()->where('id', 1)->value('generation'));
        $this->assertSame('After outer commit', $service->getAll($product->category_id)->first()->name);
    }

    public function test_product_etag_after_committed_mutation_does_not_validate_lagged_replica_fields(): void
    {
        [$user, $product] = $this->fixtures();
        $inventory = Mockery::mock(InventoryGrpcClient::class);
        $inventory->shouldReceive('getStock')->times(4)->with($product->id)->andReturn(2);
        $this->app->instance(InventoryGrpcClient::class, $inventory);
        config(['perf.product_etag_enabled' => true]);
        $get = function (?string $tag = null) use ($user, $product) {
            DB::connection()->forgetRecordModificationState();
            auth('api')->forgetUser();
            auth('api')->setUser($user);
            $server = ['HTTP_ACCEPT' => 'application/json'];
            if ($tag !== null) { $server['HTTP_IF_NONE_MATCH'] = $tag; }
            $request = Request::create('/api/v1/products/'.$product->id, 'GET', [], [], [], $server);
            $kernel = $this->app->make(Kernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return $response;
        };
        $this->pause();
        $first = $get();
        $this->assertSame(200, $first->getStatusCode());
        $tag = $first->headers->get('ETag');
        $this->assertNotEmpty($tag);
        app(ProductService::class)->update($product->id, ['name' => 'Committed detail mutation']);
        $this->assertSame('Committed detail mutation', DB::table('products')->useWritePdo()->where('id', $product->id)->value('name'));
        $this->assertSame('Primary catalog', Round2Environment::replicaControl($this->manifest)
            ->query('SELECT name FROM products WHERE id='.(int) $product->id)->fetchColumn());
        $next = $get($tag);
        $this->assertSame(200, $next->getStatusCode(), 'A committed catalog write must not yield stale 304 from the lagged replica.');
        $this->assertNotSame($tag, $next->headers->get('ETag'));
        $this->assertSame('Committed detail mutation', json_decode($next->getContent(), true)['data']['name']);

        // Eager relations choose their own PDO; checking only the product is insufficient.
        $tag = $next->headers->get('ETag');
        Category::query()->useWritePdo()->whereKey($product->category_id)->update(['name' => 'Committed category mutation']);
        $next = $get($tag);
        $this->assertSame(200, $next->getStatusCode());
        $this->assertNotSame($tag, $next->headers->get('ETag'));
        $this->assertSame('Committed category mutation', json_decode($next->getContent(), true)['data']['category']['name']);
        $tag = $next->headers->get('ETag');
        $unchanged = $get($tag);
        $this->assertSame(304, $unchanged->getStatusCode());
        $this->assertSame('', $unchanged->getContent());
        $this->assertSame('Primary catalog', DB::table('products')->where('id', $product->id)->value('name'), 'Primary routing must not leak to other reads.');

        app(ProductService::class)->delete($product->id);
        $this->assertSame(404, $get($tag)->getStatusCode(), 'A deleted primary product must not validate a stale replica representation.');
    }
}
