<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationDevice;
use App\Modules\Notifications\Models\OrderNotification;
use App\Modules\Notifications\Push\PushProviders;
use App\Modules\Notifications\Push\PushResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PushDeliveryProcessor
{
    public function process(int $id): ?int
    {
        // Never allow a caller using the sync queue to send inside a business transaction.
        if (DB::transactionLevel() > 0) {
            return 120;
        }
        $claim = DB::transaction(function () use ($id) {
            $delivery = NotificationDelivery::whereKey($id)->lockForUpdate()->first();
            if (! $delivery || ! in_array($delivery->status, ['pending', 'sending'], true)) {
                return null;
            }
            foreach (['lease_until', 'next_attempt_at'] as $field) {
                if ($delivery->$field?->isFuture()) {
                    return max(1, (int) ceil(now()->diffInSeconds($delivery->$field)));
                }
            }
            $notification = OrderNotification::findOrFail($delivery->notification_id);
            $device = NotificationDevice::find($delivery->device_id);
            if (! $device || ! $device->active || $device->user_id !== $notification->user_id || $device->revision !== $delivery->device_revision) {
                $delivery->update(['status' => 'skipped_device_changed']);

                return null;
            }
            if ($delivery->driver !== config('order_notifications.driver') || $delivery->driver === 'disabled') {
                $delivery->update(['status' => config('order_notifications.driver') === 'disabled' ? 'skipped_disabled' : 'skipped_driver_changed']);

                return null;
            }
            if (! in_array($delivery->driver, ['firebase', 'fake'], true)) {
                $delivery->update(['status' => 'failed', 'error_code' => 'unknown_push_driver']);

                return null;
            }
            if ($delivery->attempts >= config('order_notifications.max_attempts')) {
                $delivery->update(['status' => 'failed', 'error_code' => 'attempts_exhausted_after_interruption']);

                return null;
            }
            $delivery->update(['status' => 'sending', 'attempts' => $delivery->attempts + 1, 'lease_until' => now()->addSeconds(120), 'next_attempt_at' => null]);

            return [$delivery, $notification, $device];
        });
        if (! is_array($claim)) {
            return $claim;
        }
        [$delivery, $notification, $device] = $claim;
        // Re-read after claiming and just before network I/O. Logout/rotation can
        // still race an already-started network request; see the Android contract.
        $current = $device->fresh();
        if (! $current || ! $current->active || $current->user_id !== $notification->user_id || $current->revision !== $delivery->device_revision) {
            $delivery->update(['status' => 'skipped_device_changed', 'lease_until' => null]);

            return null;
        }
        try {
            $result = app(PushProviders::class)->for($delivery->driver)->send($current->token, $notification->payload(), $id);
        } catch (Throwable) {
            // A worker restart will recover the lease, with a bounded budget.
            $result = new PushResult('retry', 'provider_interrupted');
        }

        return DB::transaction(function () use ($delivery, $device, $result) {
            $locked = NotificationDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'sending' || $locked->attempts !== $delivery->attempts) {
                return null;
            }
            $delay = null;
            $status = $result->status;
            if ($status === 'retry') {
                if ($locked->attempts < config('order_notifications.max_attempts')) {
                    $base = min(3600, config('order_notifications.backoff') * (2 ** ($locked->attempts - 1)));
                    $delay = max($result->retryAfter, $base + random_int(0, max(1, (int) ($base / 5))));
                    $status = 'pending';
                } else {
                    $status = 'failed';
                }
            }
            if ($status === 'invalid_token') {
                // An old FCM failure must never disable a newly rotated token.
                NotificationDevice::whereKey($device->id)->where('revision', $delivery->device_revision)
                    ->where('token_hash', $device->token_hash)
                    ->update(['active' => false, 'token' => null, 'token_hash' => null, 'updated_at' => now()]);
                $status = 'failed';
            }
            $locked->update([
                'status' => $status, 'error_code' => $result->code,
                'lease_until' => null, 'next_attempt_at' => $delay !== null ? now()->addSeconds($delay) : null,
                'accepted_at' => $status === 'accepted' ? now() : null,
                'provider_message_id' => $result->messageId,
            ]);
            if ($status === 'failed') {
                Log::warning('Order push failed', ['delivery_id' => $locked->id, 'driver' => $locked->driver, 'code' => $result->code, 'attempts' => $locked->attempts]);
            }

            return $delay;
        });
    }
}
