<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;


class OrderService
{
    public function __construct(
        private InventoryGrpcClient $inventoryClient
    ) {}

    public function getUserOrders(User $user): LengthAwarePaginator
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->with('items')
            ->latest()
            ->paginate(10);
    }

    public function getAllOrders(): LengthAwarePaginator
    {
        return Order::query()
            ->with(['user', 'items'])
            ->latest()
            ->paginate(15);
    }

    public function getUserOrder(User $user, int $orderId): Order
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->with('items')
            ->findOrFail($orderId);
    }

    public function createOrder(User $user): Order
    {
        $reservationId = (string) Str::uuid();
        $reservationCreated = false;

        try {
            $order = DB::transaction(function () use (
                $user,
                $reservationId,
                &$reservationCreated
            ) {
                $cart = Cart::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (!$cart) {
                    throw ValidationException::withMessages([
                        'cart' => 'Your cart is empty.',
                    ]);
                }

                $cartItems = $cart->items()
                    // Keep products available until the order and its inventory reservation are saved.
                    ->with(['product' => fn ($products) => $products->sharedLock()])
                    ->lockForUpdate()
                    ->get();

                if ($cartItems->isEmpty()) {
                    throw ValidationException::withMessages([
                        'cart' => 'Your cart is empty.',
                    ]);
                }

                $reservationItems = [];
                $orderItems = [];
                $totalCents = 0;

                foreach ($cartItems as $cartItem) {
                    $product = $cartItem->product;

                    if (!$product) {
                        throw ValidationException::withMessages([
                            'cart' => 'A product in your cart is no longer available.',
                        ]);
                    }

                    $quantity = (int) $cartItem->quantity;

                    if ($quantity <= 0) {
                        throw ValidationException::withMessages([
                            'cart' => 'Invalid product quantity.',
                        ]);
                    }

                    // Convert decimal price to integer cents to avoid float errors.
                    $priceParts = explode('.', (string) $product->price);
                    $whole = (int) ($priceParts[0] ?? 0);
                    $fraction = str_pad($priceParts[1] ?? '0', 2, '0');

                    $unitPriceCents = ($whole * 100) + (int) substr($fraction, 0, 2);
                    $subtotalCents = $unitPriceCents * $quantity;

                    $totalCents += $subtotalCents;

                    $reservationItems[] = [
                        'product_id' => (int) $product->id,
                        'quantity' => $quantity,
                    ];

                    $orderItems[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'unit_price' => number_format($unitPriceCents / 100, 2, '.', ''),
                        'quantity' => $quantity,
                        'subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
                    ];
                }

                // Reserve all requested products in Inventory service.
                $this->inventoryClient->reserveStock(
                    $reservationId,
                    $reservationItems
                );

                $reservationCreated = true;

                $order = Order::create([
                    'user_id' => $user->id,
                    'status' => 'pending',
                    'total_amount' => number_format($totalCents / 100, 2, '.', ''),
                    'reservation_id' => $reservationId,
                ]);

                $order->items()->createMany($orderItems);

                // Empty the cart only after the order and its items are saved.
                $cart->items()->delete();

                return $order->load('items');
            });

            return $order;
        } catch (Throwable $exception) {
            // If stock was reserved but saving the order failed, compensate.
            if ($reservationCreated) {
                try {
                    $this->inventoryClient->releaseStock($reservationId);
                } catch (Throwable $releaseException) {
                    Log::critical('Failed to release inventory after order creation failure.', [
                        'reservation_id' => $reservationId,
                        'user_id' => $user->id,
                        'error' => $releaseException->getMessage(),
                    ]);
                }
            }

            throw $exception;
        }
    }

    public function cancelUserOrder(User $user, int $orderId): Order
    {
        $lockedOrder = fn () => Order::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->findOrFail($orderId);

        // Persist the intent before releasing stock. If the RPC response is lost,
        // retries can finish the cancellation while confirmation stays blocked.
        DB::transaction(function () use ($lockedOrder) {
            $order = $lockedOrder();
            if (!in_array($order->status, ['pending', Order::CANCELLING, 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending orders can be cancelled.',
                ]);
            }

            if ($order->status === 'pending') {
                $this->reservationId($order);
                $order->update(['status' => Order::CANCELLING]);
            }
        });

        return DB::transaction(function () use ($lockedOrder) {
            $order = $lockedOrder();

            return $order->status === 'cancelled'
                ? $order->load('items')
                : $this->applyStatus($order, 'cancelled');
        });
    }

    public function updateStatus(int $orderId, string $status): Order
    {
        return DB::transaction(function () use ($orderId, $status) {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            $allowedTransitions = [
                'pending' => ['confirmed', 'cancelled'],
                Order::CANCELLING => ['cancelled'],
                'confirmed' => ['processing', 'cancelled'],
                'processing' => ['shipped', 'cancelled'],
                'shipped' => ['delivered'],
                'delivered' => [],
                'cancelled' => [],
            ];

            abort_unless(
                in_array($status, $allowedTransitions[$order->status] ?? [], true),
                422,
                'Invalid order status transition.',
            );

            return $this->applyStatus($order, $status);
        });
    }

    private function applyStatus(Order $order, string $status): Order
    {
        if ($status === 'shipped') {
            $this->inventoryClient->commitReservation($this->reservationId($order));
        } elseif ($status === 'cancelled') {
            $this->inventoryClient->releaseStock($this->reservationId($order));
        }

        $order->update(['status' => $status]);

        return $order->load('items');
    }

    private function reservationId(Order $order): string
    {
        abort_if(!$order->reservation_id, 422, 'Order does not have an inventory reservation.');

        return $order->reservation_id;
    }
}
