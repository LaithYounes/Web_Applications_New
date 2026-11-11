<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Services\PushDeliveryProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendOrderPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 0; // Durable per-device attempt budget is stored in the DB.

    public int $timeout = 90;

    public function __construct(public int $deliveryId) {}

    public function handle(PushDeliveryProcessor $processor): void
    {
        $delay = $processor->process($this->deliveryId);
        if ($delay !== null) {
            $this->release($delay);
        }
    }
}
