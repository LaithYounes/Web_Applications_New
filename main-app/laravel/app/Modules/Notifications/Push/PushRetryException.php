<?php

namespace App\Modules\Notifications\Push;

use RuntimeException;

class PushRetryException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('oauth_temporarily_unavailable');
    }

    public static function delay(string $header): int
    {
        return ctype_digit($header) ? (int) $header : max(0, (strtotime($header) ?: 0) - time());
    }
}
