<?php

// Run only through Run-Round4Live.ps1 in its disposable Compose project.
use App\Models\User;
use App\Modules\Inventory\Exceptions\InventoryServiceException;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\PendingCheckout;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Products\Models\Category;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! preg_match('/^perf-r3-round4-[a-f0-9]{12}$/D', getenv('ROUND4_PROJECT') ?: '')
    || gethostname() !== getenv('ROUND4_HOSTNAME')
    || config('database.connections.mysql.database') !== 'ecommerce_main_perf'
    || config('database.connections.mysql.write.host') !== ['mysql']
    || config('order_notifications.driver') !== 'disabled') {
    throw new RuntimeException('Refusing unowned live test runtime.');
}
$rows = [];
function check(bool $ok, string $message): void { if (! $ok) { throw new RuntimeException($message); } }
function scenario(string $name, Closure $body): void {
    global $rows;
    try { $body(); $rows[] = ['case' => $name, 'pass' => true]; }
    catch (Throwable $e) {
        $rows[] = ['case' => $name, 'pass' => false, 'error_type' => get_class($e), 'message' => $e->getMessage()];
        echo json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit(1);
    }
}
function http(?User $user, string $method, string $uri, array $body = [], array $headers = [], array $files = []): array {
    global $app;
    DB::connection()->forgetRecordModificationState();
    auth('api')->forgetUser();
    if ($user) { auth('api')->setUser($user); }
    $server = ['HTTP_ACCEPT' => 'application/json'];
    foreach ($headers as $key => $value) { $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value; }
    if (! $files) { $server['CONTENT_TYPE'] = 'application/json'; }
    $request = Request::create($uri, $method, $files ? $body : [], [], $files, $server, $files ? null : json_encode($body));
    $kernel = $app->make(Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return [$response->getStatusCode(), json_decode($response->getContent(), true), $response];
}
function status(array $response, int $expected): array {
    check($response[0] === $expected, 'Expected HTTP '.$expected.', got '.$response[0]);
    return $response;
}
class RealInventoryFault extends InventoryGrpcClient {
    public string $fault = '';
    public array $ids = [];
    public function reserveStock(string $reservationId, array $items): void {
        $this->ids[] = $reservationId;
        parent::reserveStock($reservationId, $items);
        if ($this->fault === 'reserve') { $this->fault = ''; throw new InventoryServiceException('Injected lost reply after real reserve'); }
    }
    public function releaseStock(string $reservationId): void {
        parent::releaseStock($reservationId);
        if ($this->fault === 'release') { $this->fault = ''; throw new InventoryServiceException('Injected lost reply after real release'); }
    }
    public function commitReservation(string $reservationId): void {
        parent::commitReservation($reservationId);
        if ($this->fault === 'commit') { $this->fault = ''; throw new InventoryServiceException('Injected lost reply after real commit'); }
    }
}
$inventory = new RealInventoryFault;
$app->instance(InventoryGrpcClient::class, $inventory);
$admin = User::factory()->create(['role' => 'admin']);
$user = User::factory()->create(['role' => 'customer']);
$other = User::factory()->create(['role' => 'customer']);
$category = Category::create(['name' => 'Round4 live']);
$product = null;
scenario('real-product-image-create-initial-stock-idempotency-roles', function () use ($admin, $user, $category, $inventory, &$product) {
    $body = ['category_id' => $category->id, 'name' => 'Round4 product', 'price' => '12.50', 'initial_quantity' => 5];
    status(http(null, 'POST', '/api/v1/products', $body), 401);
    status(http($user, 'POST', '/api/v1/products', $body), 403);
    $image = fn () => UploadedFile::fake()->createWithContent('round4.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK1sAAAAASUVORK5CYII='
    ));
    $first = status(http($admin, 'POST', '/api/v1/products', $body, ['Idempotency-Key' => 'round4-create'], ['image' => $image()]), 201);
    $id = $first[1]['data']['id'];
    $again = status(http($admin, 'POST', '/api/v1/products', $body, ['Idempotency-Key' => 'round4-create'], ['image' => $image()]), 201);
    check($again[1]['data']['id'] === $id, 'Create idempotency');
    check($inventory->getStock($id) === 5, 'Initial stock must not double');
    $product = App\Modules\Products\Models\Product::findOrFail($id);
    check(Illuminate\Support\Facades\Storage::disk('public')->exists($product->image_path), 'Image persisted');
});
scenario('real-etag-200-304-zero-stock-fields-and-disabled', function () use ($product, $inventory, $admin) {
    config(['perf.product_etag_enabled' => true]);
    $url = '/api/v1/products/'.$product->id;
    $first = status(http(null, 'GET', $url), 200);
    $tag = $first[2]->headers->get('ETag');
    check((bool)$tag, 'ETag missing');
    foreach ([$tag, 'W/'.$tag, '"other", '.$tag, '*'] as $value) {
        $r = status(http(null, 'GET', $url, [], ['If-None-Match' => $value]), 304);
        check($r[2]->getContent() === '', '304 body must be empty');
    }
    status(http($admin, 'PATCH', $url, ['name' => 'Round4 updated']), 200);
    $next = status(http(null, 'GET', $url, [], ['If-None-Match' => $tag]), 200);
    check($next[2]->headers->get('ETag') !== $tag, 'Changed field validator');
    $tag = $next[2]->headers->get('ETag');
    $inventory->reserveStock('round4-zero', [['product_id' => $product->id, 'quantity' => 5]]);
    $zero = status(http(null, 'GET', $url, [], ['If-None-Match' => $tag]), 200);
    check($zero[1]['data']['available_quantity'] === 0, 'Real zero stock');
    config(['perf.product_etag_enabled' => false]);
    $r = status(http(null, 'GET', $url, [], ['If-None-Match' => '*']), 200);
    check(! $r[2]->headers->has('ETag'), 'Disabled ETag');
    config(['perf.product_etag_enabled' => true]);
    $inventory->releaseStock('round4-zero');
    $host = config('services.inventory.host');
    config(['services.inventory.host' => '127.0.0.1:1']);
    try { status(http(null, 'GET', $url, [], ['If-None-Match' => '*']), 503); }
    finally { config(['services.inventory.host' => $host]); }
});
scenario('real-checkout-empty-insufficient-and-ownership', function () use ($user, $other, $product) {
    status(http($user, 'POST', '/api/v1/orders'), 422);
    status(http($user, 'POST', '/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 6]), 201);
    status(http($user, 'POST', '/api/v1/orders'), 409);
    check(PendingCheckout::count() === 0, 'Definitive rejection cleans intent');
    $item = App\Modules\Orders\Models\CartItem::firstOrFail();
    status(http($other, 'PUT', '/api/v1/cart/items/'.$item->id, ['quantity' => 1]), 404);
    status(http($user, 'PUT', '/api/v1/cart/items/'.$item->id, ['quantity' => 1]), 200);
});
$order = null;
scenario('real-lost-reserve-reply-same-id-and-cancellation-recovery', function () use ($user, $other, $admin, $inventory, $product, &$order) {
    $inventory->fault = 'reserve';
    status(http($user, 'POST', '/api/v1/orders'), 503);
    $id = PendingCheckout::firstOrFail()->reservation_id;
    check($inventory->getStock($product->id) === 4, 'Real hold exists after lost reply');
    $created = status(http($user, 'POST', '/api/v1/orders'), 201);
    $order = Order::findOrFail($created[1]['data']['id']);
    check($order->reservation_id === $id && $inventory->ids[count($inventory->ids)-1] === $id, 'Reservation identity retained');
    check($inventory->getStock($product->id) === 4, 'Retry did not double reserve');
    status(http($other, 'GET', '/api/v1/orders/'.$order->id), 404);
    status(http(null, 'GET', '/api/v1/orders/'.$order->id), 401);
    $inventory->fault = 'release';
    status(http($user, 'POST', '/api/v1/orders/'.$order->id.'/cancel'), 503);
    check($inventory->getStock($product->id) === 5, 'Real release happened');
    status(http($admin, 'PATCH', '/api/v1/orders/'.$order->id.'/status', ['status' => 'confirmed']), 422);
    status(http($user, 'POST', '/api/v1/orders/'.$order->id.'/cancel'), 200);
    $history = $order->statusHistory()->count();
    $notifications = DB::table('order_notifications')->count();
    status(http($user, 'POST', '/api/v1/orders/'.$order->id.'/cancel'), 200);
    check($order->statusHistory()->count() === $history && DB::table('order_notifications')->count() === $notifications, 'Cancellation replay duplicated event');
});
scenario('real-reserve-local-save-rollback-worker-recovery-no-duplicate-events', function () use ($user, $product, $inventory, $admin, &$order) {
    status(http($user, 'POST', '/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1]), 201);
    $orders = Order::count(); $hist = DB::table('order_status_histories')->count(); $notes = DB::table('order_notifications')->count();
    $fail = true;
    Order::created(function () use (&$fail) { if ($fail) { $fail = false; throw new RuntimeException('Injected local save failure'); } });
    status(http($user, 'POST', '/api/v1/orders'), 500);
    $id = PendingCheckout::firstOrFail()->reservation_id;
    check(Order::count() === $orders && DB::table('order_status_histories')->count() === $hist && DB::table('order_notifications')->count() === $notes, 'Local rollback leaked rows/events');
    check($inventory->getStock($product->id) === 4, 'Reservation survived local rollback');
    check(Artisan::call('orders:recover-checkouts', ['--min-age' => 0]) === 0, 'Recovery worker failed');
    $order = Order::where('reservation_id', $id)->firstOrFail();
    check(Order::count() === $orders + 1 && PendingCheckout::count() === 0, 'Recovery counts');
    $notes = DB::table('order_notifications')->count();
    app(OrderService::class)->finishCheckout($id);
    check(DB::table('order_notifications')->count() === $notes, 'Recovery replay notification duplication');
    foreach (['confirmed', 'processing'] as $state) { status(http($admin, 'PATCH', '/api/v1/orders/'.$order->id.'/status', ['status' => $state]), 200); }
    $hist = $order->statusHistory()->count(); $notes = DB::table('order_notifications')->count();
    $inventory->fault = 'commit';
    status(http($admin, 'PATCH', '/api/v1/orders/'.$order->id.'/status', ['status' => 'shipped']), 503);
    check($order->fresh()->status === 'processing' && $order->statusHistory()->count() === $hist && DB::table('order_notifications')->count() === $notes, 'Lost commit reply persisted local transition');
    status(http($admin, 'PATCH', '/api/v1/orders/'.$order->id.'/status', ['status' => 'shipped']), 200);
    check($inventory->getStock($product->id) === 4, 'Repeated commit changed stock');
    status(http($user, 'POST', '/api/v1/orders/'.$order->id.'/cancel'), 422);
    status(http($admin, 'PATCH', '/api/v1/orders/'.$order->id.'/status', ['status' => 'delivered']), 200);
});
scenario('real-existing-data-index-migration-and-product-delete', function () use ($admin, $product) {
    // Retain the old real Redis payload, fail network reads/writes, mutate, recover.
    status(http(null, 'GET', '/api/v1/products'), 200);
    $redis = config('database.redis.cache');
    config(['database.redis.cache.port' => 1, 'database.redis.cache.timeout' => 0.2,
        'database.redis.cache.read_timeout' => 0.2]);
    app('redis')->purge('cache');
    try {
        status(http($admin, 'PATCH', '/api/v1/products/'.$product->id, ['name' => 'Redis outage mutation']), 200);
        $r = status(http(null, 'GET', '/api/v1/products'), 200);
        check($r[1]['data'][0]['name'] === 'Redis outage mutation', 'Redis outage fallback stale');
    } finally { config(['database.redis.cache' => $redis]); app('redis')->purge('cache'); }
    $r = status(http(null, 'GET', '/api/v1/products'), 200);
    check($r[1]['data'][0]['name'] === 'Redis outage mutation', 'Recovered Redis revived stale payload');
    $counts = [User::count(), Order::count(), DB::table('order_items')->count(), DB::table('order_notifications')->count()];
    $migration = require database_path('migrations/2026_11_10_170000_add_orders_created_at_index.php');
    $migration->down(); $migration->up();
    $cacheMigration = require database_path('migrations/2026_11_13_000000_create_catalog_cache_state_table.php');
    $cacheMigration->down(); $cacheMigration->up();
    check($counts === [User::count(), Order::count(), DB::table('order_items')->count(), DB::table('order_notifications')->count()], 'Migration changed fixture');
    status(http($admin, 'DELETE', '/api/v1/products/'.$product->id), 200);
    status(http(null, 'GET', '/api/v1/products/'.$product->id), 404);
});
echo json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
