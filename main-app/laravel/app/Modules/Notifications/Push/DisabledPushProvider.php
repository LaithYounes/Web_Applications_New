<?php

namespace App\Modules\Notifications\Push;

class DisabledPushProvider implements PushProvider
{
    public function send(string $token, array $data, int $deliveryId): PushResult
    {
        return new PushResult('skipped_disabled');
    }
}
