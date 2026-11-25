<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Jobs\SendOrderPush;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationDevice;
use App\Modules\Notifications\Models\OrderNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Str;

class OrderNotificationRecorder
{
    private bool $suppressed = false;

    public function withoutRecording(callable $callback): mixed
    {
        $previous = $this->suppressed;
        $this->suppressed = true;
        try {
            return $callback();
        } finally {
            $this->suppressed = $previous;
        }
    }

    public function record(Order $order, OrderStatusHistory $event): void
    {
        if ($this->suppressed) {
            return;
        }
        $type = $event->to_status === 'pending' && $event->from_status === null
            ? OrderNotificationType::Created
            : ($event->from_status !== null ? OrderNotificationType::tryFrom('order.'.$event->to_status) : null);
        if (! $type) {
            return;
        }

        $connection = $order->getConnection();
        $connection->transaction(function () use ($connection, $order, $event, $type): void {

            $user = $connection->table('users')->where('id', $order->user_id)->lockForUpdate()->first();
            if ($user->role !== 'customer') {
                return;
            }
            $id = (string) Str::uuid();
            $driver = config('order_notifications.driver');
            $devices = NotificationDevice::on($connection->getName())->where('user_id', $order->user_id)->where('active', true)->get();

            $inserted = $connection->table('order_notifications')->insertOrIgnore([
                'notification_id' => $id, 'event_id' => $event->id, 'user_id' => $order->user_id,
                'order_id' => $order->id, 'order_status' => $event->to_status,
                'type' => $type->value, 'title' => $type->title(), 'body' => $type->body($order->id),
                'push_mode' => $driver === 'disabled' ? 'disabled' : ($devices->isEmpty() ? 'no_devices' : $driver),
                'created_at' => $event->changed_at->utc()->format('Y-m-d H:i:s.u'),
            ]);
            if (! $inserted) {
                return;
            }
            if ($type === OrderNotificationType::Created) {

                $now = now();

                $connection->table('order_email_outbox')->insert([
                    'message_id' => (string) Str::uuid(),
                    'event_id' => $event->id,
                    'order_id' => $order->id,
                    'recipient_email' => $user->email,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $notification = OrderNotification::on($connection->getName())->where('notification_id', $id)->firstOrFail();
            
            $queue = new DatabaseQueue($connection, 'jobs', 'notifications', 180, false);
            $queue->setContainer(app());
            foreach ($devices as $device) {
                $delivery = NotificationDelivery::on($connection->getName())->create([
                    'notification_id' => $notification->id, 'device_id' => $device->id,
                    'device_revision' => $device->revision, 'driver' => $driver,
                    'status' => $driver === 'disabled' ? 'skipped_disabled' : 'pending',
                ]);
                if ($driver !== 'disabled') {
                    $queue->push(new SendOrderPush($delivery->id), '', 'notifications');
                }
            }
        });
    }
}
