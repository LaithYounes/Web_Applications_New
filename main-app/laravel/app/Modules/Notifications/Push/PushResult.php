<?php

namespace App\Modules\Notifications\Push;

final readonly class PushResult
{
    public function __construct(
        public string $status,
        public ?string $code = null,
        public int $retryAfter = 0,
        public ?string $messageId = null,
    ) {}
}
