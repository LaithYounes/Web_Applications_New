<?php

namespace App\Modules\Notifications\Push;

class PushProviders
{
    public function for(string $driver): PushProvider
    {
        return match ($driver) {
            'firebase' => app(FirebasePushProvider::class),
            'fake' => app(FakePushProvider::class),
            default => new DisabledPushProvider,
        };
    }
}
