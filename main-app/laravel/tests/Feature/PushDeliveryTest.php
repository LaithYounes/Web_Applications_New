<?php

namespace Tests\Feature;

use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\OrderNotification;
use App\Modules\Notifications\Services\DeviceRegistry;
use App\Modules\Notifications\Services\PushDeliveryProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\NotificationFixtures;
use Tests\TestCase;

class PushDeliveryTest extends TestCase
{
    use DatabaseMigrations, NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['order_notifications.driver' => 'fake']);
    }

    public function test_partial_success_durable_backoff_exhaustion_and_job_replay(): void
    {
        config(['order_notifications.max_attempts' => 2]);
        $user = $this->customer();
        $first = $this->device($user, 'first');
        $second = $this->device($user, 'second');
        $order = $this->order($user);
        $history = $order->statusHistory()->count();
        $deliveries = NotificationDelivery::orderBy('id')->get();
        $processor = app(PushDeliveryProcessor::class);
        $this->assertNull($processor->process($deliveries[0]->id));
        $this->assertNull($processor->process($deliveries[0]->id));
        config(['order_notifications.fake_results' => ['retry']]);
        $delay = $processor->process($deliveries[1]->id);
        $this->assertGreaterThanOrEqual(60, $delay);
        $this->assertSame('pending', $deliveries[1]->fresh()->status);
        $this->assertSame(1, $deliveries[1]->fresh()->attempts);
        $this->assertGreaterThan(0, $processor->process($deliveries[1]->id));
        $this->assertDatabaseCount('notification_push_attempts', 2);
        $this->travel($delay + 1)->seconds();
        $this->assertNull($processor->process($deliveries[1]->id));
        $this->assertSame('failed', $deliveries[1]->fresh()->status);
        $this->assertSame(2, $deliveries[1]->fresh()->attempts);
        $this->assertSame('accepted', $deliveries[0]->fresh()->status);
        $this->assertDatabaseCount('order_notifications', 1);
        $this->assertDatabaseCount('notification_push_attempts', 3);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame($history, $order->statusHistory()->count());
        $this->assertTrue($first->fresh()->active);
        $this->assertTrue($second->fresh()->active);
    }

    public function test_logout_rotation_account_switch_and_missing_devices_never_send_stale_jobs(): void
    {
        $user = $this->customer();
        $registry = app(DeviceRegistry::class);
        $device = $this->device($user);
        $this->order($user);
        $old = NotificationDelivery::firstOrFail();
        $registry->logout($user, $device->session_id);
        $other = $this->customer();
        $registry->register($other, $device->installation_id, [
            'token' => 'new:account', 'platform' => 'android', 'expected_revision' => 1,
        ], hash('sha256', 'other-session'));
        app(PushDeliveryProcessor::class)->process($old->id);
        $this->assertSame('skipped_device_changed', $old->fresh()->status);
        $this->assertDatabaseCount('notification_push_attempts', 0);
        $this->order($user);
        $this->assertSame('no_devices', OrderNotification::orderByDesc('id')->first()->push_mode);
        $this->assertDatabaseCount('notification_deliveries', 1);
    }

    public function test_expired_claim_recovers_after_worker_crash_with_bounded_budget(): void
    {
        $user = $this->customer();
        $this->device($user);
        $this->order($user);
        $delivery = NotificationDelivery::firstOrFail();
        $delivery->update(['status' => 'sending', 'attempts' => 1, 'lease_until' => now()->addSeconds(120)]);
        $this->assertGreaterThan(0, app(PushDeliveryProcessor::class)->process($delivery->id));
        $this->assertDatabaseCount('notification_push_attempts', 0);
        $this->travel(121)->seconds();
        app(PushDeliveryProcessor::class)->process($delivery->id);
        $this->assertSame('accepted', $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertDatabaseCount('notification_push_attempts', 1);
    }

    public function test_invalid_token_only_disables_matching_registration_and_disabled_does_not_replay_old_messages(): void
    {
        $user = $this->customer();
        $first = $this->device($user, 'first');
        $second = $this->device($user, 'second');
        $this->order($user);
        config(['order_notifications.fake_results' => ['invalid_token']]);
        $deliveries = NotificationDelivery::orderBy('id')->get();
        app(PushDeliveryProcessor::class)->process($deliveries[0]->id);
        $this->assertFalse($first->fresh()->active);
        $this->assertNull($first->fresh()->token_hash);
        $this->assertTrue($second->fresh()->active);
        config(['order_notifications.driver' => 'disabled']);
        app(PushDeliveryProcessor::class)->process($deliveries[1]->id);
        $this->assertSame('skipped_disabled', $deliveries[1]->fresh()->status);
        config(['order_notifications.driver' => 'fake', 'order_notifications.fake_results' => ['accepted']]);
        app(PushDeliveryProcessor::class)->process($deliveries[1]->id);
        $this->assertDatabaseCount('notification_push_attempts', 1);
    }

    public function test_queue_enqueue_failure_rolls_back_business_event_and_notification(): void
    {
        $user = $this->customer();
        $this->device($user);
        DB::statement('DROP TABLE jobs');
        try {
            $this->order($user);
            $this->fail('Enqueue failure was swallowed');
        } catch (QueryException) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('order_status_histories', 0);
            $this->assertDatabaseCount('order_notifications', 0);
            $this->assertDatabaseCount('notification_deliveries', 0);
        }
    }
}
