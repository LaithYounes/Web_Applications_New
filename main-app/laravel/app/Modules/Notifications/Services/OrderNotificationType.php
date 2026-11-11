<?php

namespace App\Modules\Notifications\Services;

enum OrderNotificationType: string
{
    case Created = 'order.created';
    case Confirmed = 'order.confirmed';
    case Processing = 'order.processing';
    case Shipped = 'order.shipped';
    case Delivered = 'order.delivered';
    case Cancelled = 'order.cancelled';

    public function title(): string
    {
        return match ($this) {
            self::Created => 'Order received',
            self::Confirmed => 'Order confirmed',
            self::Processing => 'Order processing',
            self::Shipped => 'Order shipped',
            self::Delivered => 'Order delivered',
            self::Cancelled => 'Order cancelled',
        };
    }

    public function body(int $orderId): string
    {
        return match ($this) {
            self::Created => "Your order #{$orderId} has been received and is awaiting confirmation.",
            self::Confirmed => "Your order #{$orderId} has been confirmed.",
            self::Processing => "Your order #{$orderId} is being prepared.",
            self::Shipped => "Your order #{$orderId} has been shipped.",
            self::Delivered => "Your order #{$orderId} has been delivered.",
            self::Cancelled => "Your order #{$orderId} has been cancelled.",
        };
    }
}
