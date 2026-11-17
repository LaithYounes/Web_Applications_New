<?php

namespace App\Modules\Orders\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;

class OrderCreated implements ShouldBroadcastNow, ShouldRescue
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $eventId,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.orders'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.created';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'event_id' => $this->eventId,
        ];
    }
}
