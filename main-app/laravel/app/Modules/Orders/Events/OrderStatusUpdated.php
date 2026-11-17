<?php

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Models\Order;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;

class OrderStatusUpdated implements ShouldBroadcastNow, ShouldRescue
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $eventId,
        public readonly string $status,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('orders.'.$this->orderId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.status.updated';
    }

    public function broadcastWith(): array
    {
        $cancellationPending = $this->status === Order::CANCELLING;

        return [
            'order_id' => $this->orderId,
            'event_id' => $this->eventId,
            'status' => $cancellationPending ? 'pending' : $this->status,
            'cancellation_pending' => $cancellationPending,
        ];
    }
}
