<?php

// Disposable Docker lab only. This script never resets a database.
use App\Models\User;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Notifications\Controllers\NotificationController;
use App\Modules\Notifications\Jobs\SendOrderPush;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationDevice;
use App\Modules\Notifications\Models\OrderNotification;
use App\Modules\Notifications\Push\FakePushProvider;
use App\Modules\Notifications\Push\PushResult;
use App\Modules\Notifications\Services\DeviceRegistry;
use App\Modules\Notifications\Services\OrderNotificationRecorder;
use App\Modules\Notifications\Services\PushDeliveryProcessor;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, 'FAIL: '.$error->getMessage().' at '.$error->getFile().':'.$error->getLine()."\n");
    exit(1);
});
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'ecommerce_notifications_test'
    || config('order_notifications.driver') !== 'fake') {
    throw new RuntimeException('This lab requires the isolated notifications-test Compose database and fake driver.');
}

function makeOrder(int $user): Order
{
    return Order::create(['user_id' => $user, 'total_amount' => '12.50', 'reservation_id' => (string) Str::uuid()]);
}

$mode = $argv[1] ?? 'run';
if ($mode !== 'run') {
    echo "ready\n";
    flush();
    if ($mode === 'record') {
        $event = OrderStatusHistory::findOrFail($argv[2]);
        app(OrderNotificationRecorder::class)->record($event->order, $event);
    } elseif ($mode === 'create' || $mode === 'commit-and-die') {
        makeOrder((int) $argv[2]);
        if ($mode === 'commit-and-die') {
            posix_kill(getmypid(), SIGKILL);
        }
    } elseif ($mode === 'register') {
        try {
            app(DeviceRegistry::class)->register(User::findOrFail($argv[2]), $argv[3], [
                'token' => $argv[4], 'platform' => 'android', 'expected_revision' => (int) $argv[5],
            ], $argv[6]);
            echo "registered\n";
        } catch (HttpException $e) {
            echo 'rejected:'.$e->getStatusCode()."\n";
        }
    } elseif ($mode === 'ambiguous' || $mode === 'rotate-during-send') {
        app()->instance(FakePushProvider::class, new class($mode, $argv[3] ?? '') extends FakePushProvider
        {
            public function __construct(private string $mode, private string $barrier) {}

            public function send(string $token, array $data, int $deliveryId): PushResult
            {
                if ($this->mode === 'ambiguous') {
                    parent::send($token, $data, $deliveryId);
                    posix_kill(getmypid(), SIGKILL);
                }
                echo "sending\n";
                flush();
                $until = microtime(true) + 15;
                while (! is_file($this->barrier) && microtime(true) < $until) {
                    usleep(20000);
                }
                if (! is_file($this->barrier)) {
                    throw new RuntimeException('Rotation barrier timed out');
                }

                return new PushResult('invalid_token', 'unregistered');
            }
        });
        app(PushDeliveryProcessor::class)->process((int) $argv[2]);
    }
    exit(0);
}

$checks = 0;
function verify(bool $condition, string $message): void
{
    global $checks;
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function child(array $args, array $env = []): Process
{
    $p = new Process(array_merge(['php', __FILE__], $args), base_path(), $env ?: null, timeout: 30);
    $p->start();
    $p->waitUntil(fn ($type, $output) => str_contains($output, 'ready'));

    return $p;
}
function done(Process $p): string
{
    $p->wait();
    verify($p->isSuccessful(), 'Child failed: '.$p->getErrorOutput().$p->getOutput());

    return $p->getOutput();
}
function killed(Process $p): void
{
    try {
        $p->wait();
    } catch (ProcessSignaledException $error) {
        verify($p->getTermSignal() === SIGKILL, 'Unexpected producer signal');
    }
    verify(! $p->isSuccessful(), 'Expected a killed process');
}
function worker(array $env = []): Process
{
    $p = new Process(['php', 'artisan', 'queue:work', 'notifications', '--queue=notifications', '--once', '--sleep=0', '--timeout=90'], base_path(), $env ?: null, timeout: 100);
    $p->start();

    return $p;
}
function drain(array $env = []): void
{
    $p = new Process(['php', 'artisan', 'queue:work', 'notifications', '--stop-when-empty', '--sleep=0', '--timeout=90'], base_path(), $env ?: null, timeout: 100);
    $p->mustRun();
    verify($p->isSuccessful(), 'Worker failed');
}
function api(string $method, string $path, ?string $jwt = null, array $data = [], int $status = 200): array
{
    $request = Http::baseUrl('http://127.0.0.1:8000/api/v1')->acceptJson()->timeout(20);
    if ($jwt) {
        $request = $request->withToken($jwt);
    }
    $response = $request->send($method, $path, ['json' => $data]);
    verify($response->status() === $status, 'HTTP '.$method.' '.$path.' returned '.$response->status().' instead of '.$status);

    return $response->json();
}

// Exercise an incremental installation on a DB containing pre-feature orders.
verify(! DB::getSchemaBuilder()->hasTable('migrations'), 'Start a fresh lab container; existing lab is intentionally not erased.');
$paths = array_map(fn ($path) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path), glob(database_path('migrations/*.php')));
$oldPaths = array_values(array_filter($paths, fn ($path) => ! str_contains($path, '000002_create_order_notifications')));
Artisan::call('migrate', ['--path' => $oldPaths, '--force' => true]);
$owner = User::create(['name' => 'Lab owner', 'email' => 'lab-owner@example.test', 'password' => 'LabPassword2026!', 'role' => 'customer']);
$other = User::create(['name' => 'Lab other', 'email' => 'lab-other@example.test', 'password' => 'LabPassword2026!', 'role' => 'customer']);
$admin = User::create(['name' => 'Lab admin', 'email' => 'lab-admin@example.test', 'password' => 'LabPassword2026!', 'role' => 'admin']);
DB::table('orders')->insert(['user_id' => $owner->id, 'status' => 'pending', 'total_amount' => '1.00', 'reservation_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
Artisan::call('migrate', ['--force' => true]);
verify(OrderNotification::count() === 0 && DB::table('jobs')->count() === 0, 'Migration backfilled old orders');
echo "PASS incremental migrations preserve old data without backfill\n";

// Real JWT + HTTP + gRPC, all within isolated databases.
$ownerJwt = api('POST', '/auth/login', null, ['email' => $owner->email, 'password' => 'LabPassword2026!'])['data']['token'];
$otherJwt = api('POST', '/auth/login', null, ['email' => $other->email, 'password' => 'LabPassword2026!'])['data']['token'];
$adminJwt = api('POST', '/auth/login', null, ['email' => $admin->email, 'password' => 'LabPassword2026!'])['data']['token'];
$installation = (string) Str::uuid();
api('PUT', '/notification-devices/'.$installation, $ownerJwt, ['token' => 'lab:first-token', 'platform' => 'android', 'expected_revision' => 0]);
$product = Product::create(['category_id' => Category::create(['name' => 'Lab'])->id, 'name' => 'Lab product', 'price' => '12.50', 'image_path' => 'lab.png']);
$inventory = app(InventoryGrpcClient::class);
for ($attempt = 0; $attempt < 30; $attempt++) {
    try {
        $inventory->getStock($product->id);
        break;
    } catch (Throwable $error) {
        if ($attempt === 29) {
            throw new RuntimeException('Isolated inventory gRPC never became ready');
        }
        sleep(1);
    }
}
$inventory->initializeStock($product->id, 10);
api('POST', '/cart/items', $ownerJwt, ['product_id' => $product->id, 'quantity' => 1], 201);
$orderId = api('POST', '/orders', $ownerJwt, [], 201)['data']['id'];
api('PATCH', '/orders/'.$orderId.'/status', $adminJwt, ['status' => 'confirmed']);
$list = api('GET', '/notifications', $ownerJwt)['data'];
verify(count($list) === 2 && $list[0]['type'] === 'order.confirmed', 'Notification HTTP list');
verify(api('GET', '/notifications/unread-count', $ownerJwt)['data']['unread_count'] === 2, 'Unread count');
api('PATCH', '/notifications/'.$list[0]['id'].'/read', $ownerJwt);
verify(api('GET', '/notifications/unread-count', $ownerJwt)['data']['unread_count'] === 1, 'Read count');
api('PATCH', '/notifications/'.$list[0]['id'].'/read', $otherJwt, [], 404);
verify(api('GET', '/notifications', $otherJwt)['data'] === [], 'Other-user isolation');
api('PATCH', '/notifications/read-all', $ownerJwt);
verify(api('GET', '/notifications/unread-count', $ownerJwt)['data']['unread_count'] === 0, 'Read all');
verify(DB::table('notification_push_attempts')->count() === 0, 'HTTP sent a push');
drain();
verify(NotificationDelivery::where('status', 'accepted')->count() === 2, 'Real worker did not consume jobs');
foreach (['processing', 'shipped', 'delivered'] as $status) {
    api('PATCH', '/orders/'.$orderId.'/status', $adminJwt, ['status' => $status]);
}
drain();
verify($inventory->getStock($product->id) === 9, 'Inventory changed unexpectedly');
api('POST', '/cart/items', $ownerJwt, ['product_id' => $product->id, 'quantity' => 1], 201);
$cancelId = api('POST', '/orders', $ownerJwt, [], 201)['data']['id'];
api('POST', '/orders/'.$cancelId.'/cancel', $ownerJwt);
api('POST', '/orders/'.$cancelId.'/cancel', $ownerJwt);
verify(OrderNotification::where('order_id', $cancelId)->count() === 2, 'Cancellation replay duplicated notifications');
verify($inventory->getStock($product->id) === 9, 'Cancellation did not release stock');
drain();
echo "PASS real HTTP JWT/device/checkout/admin lifecycle/cancel/list/count/read/isolation and database worker\n";

$device = NotificationDevice::where('installation_id', $installation)->firstOrFail();
$before = OrderNotification::count();
DB::beginTransaction();
$rolled = makeOrder($owner->id);
verify(DB::table('jobs')->count() === 1, 'Job not inserted inside transaction');
done(worker());
verify(DB::table('notification_push_attempts')->whereIn('delivery_id', NotificationDelivery::where('notification_id', OrderNotification::where('order_id', $rolled->id)->value('id'))->pluck('id'))->count() === 0, 'Worker observed uncommitted event');
DB::rollBack();
verify(OrderNotification::count() === $before && DB::table('jobs')->count() === 0, 'Outer rollback left work');
// Kill the producer immediately after commit. The job is already durable.
$producer = child(['commit-and-die', (string) $owner->id]);
killed($producer);
verify(! $producer->isSuccessful(), 'Producer crash was not injected');
verify(DB::table('jobs')->count() === 1, 'Post-commit producer crash lost work');
drain();
echo "PASS separate worker cannot see nested/uncommitted writes; outer rollback and producer crash after commit\n";

// Two real processes contend for the same event while the parent holds the recipient lock.
$order = Order::orderByDesc('id')->firstOrFail();
$event = $order->statusHistory->first();
DB::beginTransaction();
User::whereKey($owner->id)->lockForUpdate()->first();
$one = child(['record', (string) $event->id]);
$two = child(['record', (string) $event->id]);
usleep(250000);
verify($one->isRunning() && $two->isRunning(), 'Concurrent recorders did not wait on transaction lock');
DB::commit();
done($one);
done($two);
verify(OrderNotification::where('event_id', $event->id)->count() === 1 && DB::table('jobs')->count() === 0, 'Concurrent event replay duplicated work');

// Duplicate queue entries and two workers still produce one accepted attempt.
$order = makeOrder($owner->id);
$delivery = NotificationDelivery::orderByDesc('id')->firstOrFail();
$queue = new DatabaseQueue(DB::connection(), 'jobs', 'notifications', 180, false);
$queue->setContainer(app());
$queue->push(new SendOrderPush($delivery->id));
$one = worker();
$two = worker();
done($one);
done($two);
verify(DB::table('notification_push_attempts')->where('delivery_id', $delivery->id)->count() === 1, 'Concurrent workers duplicated a successful delivery');
// The losing duplicate job may have been released while the first claim was
// still leased. Make that harmless tombstone due and verify it drains too.
DB::table('jobs')->update(['available_at' => time() - 1]);
drain();
verify(DB::table('jobs')->count() === 0, 'Completed duplicate job did not drain');
echo "PASS concurrent event recording and two database workers with duplicate queue entries\n";

// Snapshot read-all while another process creates an order and waits on the same user.
DB::beginTransaction();
$request = Request::create('/api/v1/notifications/read-all', 'PATCH');
$request->setUserResolver(fn () => $owner);
app(NotificationController::class)->readAll($request);
$incoming = child(['create', (string) $owner->id]);
usleep(250000);
verify($incoming->isRunning(), 'Incoming notification bypassed read-all lock');
DB::commit();
done($incoming);
verify(OrderNotification::where('user_id', $owner->id)->whereNull('read_at')->count() === 1, 'Read-all swallowed a concurrent new notification');
drain();
echo "PASS read-all watermark with concurrent arrival on independent connection\n";

// Two token rotations race with the same expected revision.
DB::beginTransaction();
User::whereKey($owner->id)->lockForUpdate()->first();
$args = ['register', (string) $owner->id, $installation];
$one = child([...$args, 'lab:rotation-one', '1', $device->session_id]);
$two = child([...$args, 'lab:rotation-two', '1', $device->session_id]);
usleep(250000);
verify($one->isRunning() && $two->isRunning(), 'Rotations were not concurrent');
DB::commit();
$outputs = done($one).done($two);
verify(substr_count($outputs, 'registered') === 1 && str_contains($outputs, 'rejected:409'), 'Stale rotation accepted');
verify($device->fresh()->revision === 2, 'Concurrent rotation revision incorrect');

// An FCM failure in a separate process must not disable a newly rotated token.
makeOrder($owner->id);
$delivery = NotificationDelivery::orderByDesc('id')->firstOrFail();
$barrier = '/tmp/notification-rotation-'.Str::uuid();
$sending = child(['rotate-during-send', (string) $delivery->id, $barrier]);
$until = microtime(true) + 10;
while (! str_contains($sending->getOutput(), 'sending') && microtime(true) < $until) {
    usleep(20000);
}
verify(str_contains($sending->getOutput(), 'sending'), 'Send did not enter race barrier');
app(DeviceRegistry::class)->register($owner, $installation, ['token' => 'lab:rotation-three', 'platform' => 'android', 'expected_revision' => 2], $device->session_id);
touch($barrier);
done($sending);
unlink($barrier);
verify($device->fresh()->active && $device->fresh()->revision === 3, 'Old token error disabled new registration');
drain();
echo "PASS concurrent token rotations and old UNREGISTERED reply after new registration\n";

// A stale authenticated PUT waiting behind logout sees its session tombstone.
DB::beginTransaction();
app(DeviceRegistry::class)->logout($owner, $device->session_id);
$stale = child([...$args, 'lab:stale-token', '3', $device->session_id]);
usleep(250000);
verify($stale->isRunning(), 'PUT did not overlap logout transaction');
DB::commit();
verify(str_contains(done($stale), 'rejected:401'), 'Logged-out session resurrected a device');
verify(! $device->fresh()->active, 'Logout did not detach binding');
// Rebind using the other account; queued old-recipient work must be skipped.
app(DeviceRegistry::class)->register($owner, $installation, ['token' => 'lab:again', 'platform' => 'android', 'expected_revision' => 3], hash('sha256', 'new-owner-session'));
makeOrder($owner->id);
$oldDelivery = NotificationDelivery::orderByDesc('id')->firstOrFail();
app(DeviceRegistry::class)->logout($owner, hash('sha256', 'new-owner-session'));
app(DeviceRegistry::class)->register($other, $installation, ['token' => 'lab:other-account', 'platform' => 'android', 'expected_revision' => 4], hash('sha256', 'other-account-session'));
drain();
verify($oldDelivery->fresh()->status === 'skipped_device_changed', 'Old account push sent to new account');
echo "PASS concurrent logout/register tombstone and delayed job after account switch\n";

// Persist backoff and exhaustion using a REAL queue worker, not an interface mock.
verify(DB::table('jobs')->count() === 0, 'Previous phase left queued work');
makeOrder($other->id);
$retry = NotificationDelivery::orderByDesc('id')->firstOrFail();
done(worker(['ORDER_PUSH_FAKE_RESULTS' => 'retry', 'ORDER_PUSH_MAX_ATTEMPTS' => '2']));
verify($retry->fresh()->status === 'pending' && $retry->fresh()->attempts === 1, 'Retry was not persisted');
verify(DB::table('jobs')->where('available_at', '>', time())->exists(), 'Backoff not stored on queue job');
$retry->refresh()->update(['next_attempt_at' => now()->subMinute()]);
DB::table('jobs')->update(['available_at' => time() - 1]);
verify($retry->fresh()->next_attempt_at->isPast(), 'Integration retry timestamp did not become due');
done(worker(['ORDER_PUSH_FAKE_RESULTS' => 'retry', 'ORDER_PUSH_MAX_ATTEMPTS' => '2']));
verify($retry->fresh()->status === 'failed' && $retry->fresh()->attempts === 2,
    'Retry budget not bounded: '.json_encode($retry->fresh()->only(['status', 'attempts', 'error_code', 'next_attempt_at', 'lease_until'])));
verify(DB::table('jobs')->count() === 0, 'Terminal failure left a runnable job');
verify(Artisan::call('notifications:retry-delivery', ['id' => $retry->id]) === 0, 'Explicit delivery retry failed');
drain();
verify($retry->fresh()->status === 'accepted', 'Explicit retry not consumed');
echo "PASS real worker persisted retries/backoff/exhaustion and explicit retry\n";

// Model acceptance followed by a lost response/crash. A retry can duplicate a
// network delivery, but the public notification_id remains stable for Android.
makeOrder($other->id);
$unknown = NotificationDelivery::orderByDesc('id')->firstOrFail();
$crash = child(['ambiguous', (string) $unknown->id]);
killed($crash);
verify(! $crash->isSuccessful() && $unknown->fresh()->status === 'sending', 'Ambiguous send crash not injected');
$unknown->update(['lease_until' => now()->subSecond()]);
drain();
$receipts = DB::table('notification_push_attempts')->where('delivery_id', $unknown->id)->get();
verify($receipts->count() === 2, 'Ambiguous send was not retried');
verify(json_decode($receipts[0]->payload, true)['notification_id'] === json_decode($receipts[1]->payload, true)['notification_id'], 'Retry changed deduplication ID');
verify($unknown->fresh()->status === 'accepted', 'Crash recovery failed');
echo "PASS crash after provider acceptance recovers; duplicate network receipt has stable notification_id\n";
// DELETE changes the CAS version too: an already authenticated old PUT cannot
// reverse an opt-out after waiting for its transaction.
$binding = $device->fresh();
DB::beginTransaction();
app(DeviceRegistry::class)->detach($other, $installation, $binding->revision, $binding->session_id);
$stale = child(['register', (string) $other->id, $installation, 'lab:late-after-delete', (string) $binding->revision, $binding->session_id]);
usleep(250000);
verify($stale->isRunning(), 'PUT did not overlap DELETE transaction');
DB::commit();
verify(str_contains(done($stale), 'rejected:409'), 'Stale PUT reversed DELETE');
verify(! $device->fresh()->active && $device->fresh()->revision === $binding->revision + 1, 'DELETE did not advance revision');
echo "PASS concurrent DELETE/PUT cannot resurrect an opted-out device\n";
echo "ALL PASS: {$checks} checks on MySQL, independent processes, real HTTP/JWT/gRPC and database workers.\n";
