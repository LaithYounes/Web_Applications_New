<?php

namespace App\Modules\Notifications\Push;

use Illuminate\Support\Facades\DB;

class FakePushProvider implements PushProvider
{
    public function send(string $token, array $data, int $deliveryId): PushResult
    {
        if (! app()->environment('local', 'testing')) {
            return new PushResult('failed', 'fake_driver_forbidden');
        }
        $attempt = DB::table('notification_push_attempts')->where('delivery_id', $deliveryId)->count();
        $results = array_values(config('order_notifications.fake_results', ['accepted']));
        $result = $results[min($attempt, count($results) - 1)] ?? 'accepted';
        DB::table('notification_push_attempts')->insert([
            'delivery_id' => $deliveryId, 'payload' => json_encode($data, JSON_THROW_ON_ERROR),
            'result' => $result, 'created_at' => now(),
        ]);

        return match ($result) {
            'accepted' => new PushResult('accepted', messageId: 'fake/'.$deliveryId),
            'retry' => new PushResult('retry', 'fake_unavailable'),
            'invalid_token' => new PushResult('invalid_token', 'unregistered'),
            default => new PushResult('failed', 'fake_failure'),
        };
    }
}
