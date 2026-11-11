<?php

namespace App\Modules\Notifications\Push;

interface PushProvider
{
    public function send(string $token, array $data, int $deliveryId): PushResult;
}
