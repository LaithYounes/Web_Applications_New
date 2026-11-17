<?php

use App\Models\User;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(
    'admin.orders',
    function (User $user): bool {
        return $user->role === 'admin';
    },
    ['guards' => ['api']]
);

Broadcast::channel(
    'orders.{orderId}',
    function (User $user, string $orderId): bool {
        if (! preg_match('/\A[1-9][0-9]{0,18}\z/', $orderId)) {
            return false;
        }

        $validatedId = filter_var(
            $orderId,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($validatedId === false) {
            return false;
        }

        return Order::query()
            ->useWritePdo()
            ->whereKey($validatedId)
            ->where('user_id', $user->getAuthIdentifier())
            ->exists();
    },
    ['guards' => ['api']]
);
