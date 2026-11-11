<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Exceptions\InventoryRejectedException;
use App\Modules\Inventory\Exceptions\InventoryServiceException;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Notifications\Models\OrderNotification;
use App\Modules\Notifications\Services\OrderNotificationRecorder;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Services\OrderService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoOrderSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\NotificationFixtures;
use Tests\TestCase;

class OrderNotificationsTest extends TestCase
{
    use DatabaseMigrations, NotificationFixtures;

    public function test_api_lifecycle_snapshots_all_five_messages_for_owner_and_never_pushes_during_http(): void
    {
        config(['order_notifications.driver' => 'fake']);
        $owner = $this->customer();
        $this->device($owner);
        $this->basket($owner);
        $inventory = $this->mock(InventoryGrpcClient::class);
        $inventory->shouldReceive('reserveStock')->once();
        $inventory->shouldReceive('commitReservation')->once();
        $this->actingAs($owner, 'api')->postJson('/api/v1/orders')->assertCreated();
        $order = Order::firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'api');
        foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $status) {
            $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => $status])->assertOk();
        }
        $notifications = OrderNotification::orderBy('id')->get();
        $this->assertSame(['order.created', 'order.confirmed', 'order.processing', 'order.shipped', 'order.delivered'], $notifications->pluck('type')->all());
        $this->assertSame([
            "Your order #{$order->id} has been received and is awaiting confirmation.", "Your order #{$order->id} has been confirmed.",
            "Your order #{$order->id} is being prepared.", "Your order #{$order->id} has been shipped.", "Your order #{$order->id} has been delivered.",
        ], $notifications->pluck('body')->all());
        $this->assertSame(['Order received', 'Order confirmed', 'Order processing', 'Order shipped', 'Order delivered'], $notifications->pluck('title')->all());
        foreach ($notifications as $notification) {
            $this->assertSame($owner->id, $notification->user_id);
            $this->assertNotEmpty($notification->title);
            $this->assertSame($notification->order_status, OrderStatusHistory::findOrFail($notification->event_id)->to_status);
        }
        $this->assertDatabaseCount('notification_deliveries', 5);
        $this->assertDatabaseCount('jobs', 5);
        $this->assertDatabaseCount('notification_push_attempts', 0);
        $this->artisan('queue:work', ['connection' => 'notifications', '--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notification_push_attempts', 5);
        $payload = json_decode(DB::table('notification_push_attempts')->orderBy('id')->first()->payload, true);
        $this->assertSame('pending', $payload['order_status']);
        $this->assertSame('order.created', $payload['type']);
        $this->assertSame($notifications[0]->notification_id, $payload['notification_id']);
        $this->assertSame((string) $order->id, $payload['order_id']);
        $this->assertSame('Order received', $payload['title']);
        $this->assertSame("Your order #{$order->id} has been received and is awaiting confirmation.", $payload['body']);
    }

    public function test_rejected_and_ambiguous_checkout_then_system_recovery_and_replay(): void
    {
        config(['order_notifications.driver' => 'fake']);
        $user = $this->customer();
        $this->device($user);
        $this->basket($user);
        $inventory = $this->mock(InventoryGrpcClient::class);
        $inventory->shouldReceive('reserveStock')->once()->andThrow(new InventoryRejectedException('No stock'));
        $this->actingAs($user, 'api')->postJson('/api/v1/orders')->assertConflict();
        $this->assertDatabaseCount('order_notifications', 0);
        $inventory->shouldReceive('reserveStock')->once()->andThrow(new InventoryServiceException('Timeout'));
        $this->postJson('/api/v1/orders')->assertServiceUnavailable();
        $this->assertDatabaseCount('order_notifications', 0);
        $this->assertDatabaseCount('pending_checkouts', 1);
        $inventory->shouldReceive('reserveStock')->once();
        $this->artisan('orders:recover-checkouts', ['--min-age' => 0])->assertSuccessful();
        $order = Order::firstOrFail();
        app(OrderService::class)->finishCheckout($order->reservation_id);
        $this->assertDatabaseCount('order_notifications', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('order_notifications', ['user_id' => $user->id, 'type' => 'order.created']);
        $this->assertSame('system', $order->statusHistory->first()->actor_type);
    }

    public function test_pending_cancellation_release_failure_retry_and_system_completion_notify_once(): void
    {
        $user = $this->customer();
        $order = $this->order($user);
        $inventory = $this->mock(InventoryGrpcClient::class);
        $inventory->shouldReceive('releaseStock')->once()->andThrow(new InventoryServiceException('Lost reply'));
        $this->actingAs($user, 'api')->postJson("/api/v1/orders/{$order->id}/cancel")->assertServiceUnavailable();
        $this->assertSame(Order::CANCELLING, $order->fresh()->status);
        $this->assertDatabaseCount('order_notifications', 1);
        $inventory->shouldReceive('releaseStock')->once();
        app(OrderService::class)->updateStatus($order->id, 'cancelled');
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();
        $this->assertDatabaseCount('order_notifications', 2);
        $this->assertDatabaseHas('order_notifications', [
            'user_id' => $user->id, 'type' => 'order.cancelled', 'order_status' => 'cancelled',
            'title' => 'Order cancelled', 'body' => "Your order #{$order->id} has been cancelled.",
        ]);
    }

    public function test_commit_failure_invalid_and_forbidden_transitions_have_no_success_effects(): void
    {
        $this->mock(InventoryGrpcClient::class)->shouldReceive('commitReservation')->once()->andThrow(new InventoryServiceException('Unavailable'));
        $owner = $this->customer();
        $order = $this->order($owner);
        $this->actingAs($owner, 'api')->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'api')
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])->assertUnprocessable();
        $order->update(['total_amount' => '20.00']);
        $order->save();
        $this->assertDatabaseCount('order_notifications', 1);
        app(OrderService::class)->updateStatus($order->id, 'confirmed');
        app(OrderService::class)->updateStatus($order->id, 'processing');
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'shipped'])->assertServiceUnavailable();
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertDatabaseCount('order_notifications', 3);
        $this->assertDatabaseCount('order_status_histories', 3);
    }

    public function test_outer_rollback_removes_notification_delivery_and_job_even_after_inner_save_succeeds(): void
    {
        config(['order_notifications.driver' => 'fake']);
        $user = $this->customer();
        $this->device($user);
        DB::beginTransaction();
        $this->order($user);
        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['connection' => 'notifications', '--once' => true])->assertSuccessful();
        $this->assertDatabaseCount('notification_push_attempts', 0);
        DB::rollBack();
        foreach (['orders', 'order_status_histories', 'order_notifications', 'notification_deliveries', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_failure_after_order_before_items_and_history_failure_roll_back_the_whole_checkout(): void
    {
        config(['order_notifications.driver' => 'fake']);
        $user = $this->customer();
        $this->device($user);
        $this->basket($user);
        $this->mock(InventoryGrpcClient::class)->shouldReceive('reserveStock')->once();
        OrderItem::creating(fn () => throw new RuntimeException('Item write failed'));
        $this->actingAs($user, 'api')->postJson('/api/v1/orders')->assertStatus(500);
        foreach (['orders', 'order_status_histories', 'order_notifications', 'notification_deliveries', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('pending_checkouts', 1);
        OrderStatusHistory::creating(fn () => throw new RuntimeException('History failed'));
        try {
            $this->order($user);
            $this->fail('Expected a failure');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('order_notifications', 0);
        }
    }

    public function test_replaying_event_has_one_record_and_one_job_and_disabled_is_never_backfilled(): void
    {
        $user = $this->customer();
        $this->device($user);
        $order = $this->order($user);
        $this->assertDatabaseHas('notification_deliveries', ['status' => 'skipped_disabled']);
        $this->assertDatabaseCount('jobs', 0);
        config(['order_notifications.driver' => 'fake']);
        app(OrderNotificationRecorder::class)->record($order, $order->statusHistory->first());
        $this->assertDatabaseCount('order_notifications', 1);
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertDatabaseCount('jobs', 0);
        app(OrderNotificationRecorder::class)->withoutRecording(fn () => $this->order($user));
        $this->assertDatabaseCount('order_notifications', 1);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_real_demo_seeders_preserve_history_without_local_notifications_or_push_jobs(): void
    {
        config(['order_notifications.driver' => 'fake']);
        Storage::fake('public');
        $inventory = $this->mock(InventoryGrpcClient::class);
        $inventory->shouldReceive('getStock')->andReturn(100);
        $inventory->shouldReceive('initializeStock')->andReturn(100);
        $inventory->shouldReceive('reserveStock')->andReturnNull();
        $inventory->shouldReceive('commitReservation')->andReturnNull();
        $inventory->shouldReceive('releaseStock')->andReturnNull();
        $this->seed(DemoUserSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->device(User::where('email', 'olivia.bennett@example.test')->firstOrFail());
        $this->seed(DemoOrderSeeder::class);
        $this->seed(DemoOrderSeeder::class);
        $this->assertGreaterThan(0, Order::count());
        $this->assertGreaterThan(0, OrderStatusHistory::count());
        $this->assertDatabaseCount('order_notifications', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notification_push_attempts', 0);
    }

    public function test_final_cancellation_save_failure_rolls_back_notification_then_retry_creates_it_once(): void
    {
        $this->mock(InventoryGrpcClient::class)->shouldReceive('releaseStock')->twice();
        $user = $this->customer();
        $order = $this->order($user);
        $failed = false;
        Order::updated(function (Order $saved) use (&$failed): void {
            if ($saved->status === 'cancelled' && ! $failed) {
                $failed = true;
                throw new RuntimeException('Failure after history and notification');
            }
        });
        $this->actingAs($user, 'api')->postJson("/api/v1/orders/{$order->id}/cancel")->assertStatus(500);
        $this->assertDatabaseCount('order_notifications', 1);
        $this->assertSame(Order::CANCELLING, $order->fresh()->status);
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();
        $this->assertDatabaseCount('order_notifications', 2);
        $this->assertSame(1, OrderNotification::where('type', 'order.cancelled')->count());
    }
}
