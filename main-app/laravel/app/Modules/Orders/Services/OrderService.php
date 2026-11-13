<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Inventory\Exceptions\InventoryRejectedException;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\CartItem;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\PendingCheckout;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private InventoryGrpcClient $inventoryClient
    ) {}

    public function getUserOrders(User $user): LengthAwarePaginator
    {
        // Each query (including eager relations) chooses its PDO explicitly. This
        // does not change connection state shared by subsequent worker requests.
        return $this->ordersQuery()
            ->where('user_id', $user->id)
            ->with($this->orderRelations(['items']))
            ->latest()
            ->paginate(10);
    }

    public function getAllOrders(): LengthAwarePaginator
    {
        return $this->ordersQuery()
            ->with($this->orderRelations(['user', 'items']))
            ->latest()
            ->paginate(15);
    }

    public function getUserOrder(User $user, int $orderId): Order
    {
        return $this->ordersQuery()
            ->where('user_id', $user->id)
            ->with($this->orderRelations(['items', 'statusHistory']))
            ->findOrFail($orderId);
    }

    public function getOrder(int $orderId): Order
    {
        return $this->ordersQuery()
            ->with($this->orderRelations(['items', 'statusHistory']))
            ->findOrFail($orderId);
    }

    private function orderRelations(array $relations): array
    {
        if (! config('perf.orders_use_primary', true)) {
            return $relations;
        }

        return array_fill_keys($relations, fn ($relation) => $relation->useWritePdo());
    }

    private function ordersQuery()
    {
        $query = Order::query();
        if (config('perf.orders_use_primary', true)) {
            $query->useWritePdo();
        }

        return $query;
    }

    public function createOrder(User $user): Order
    {
        // Commit the intent and immutable basket BEFORE calling another service.
        // A timeout or process crash must not erase the identity of the operation.
        $checkout = DB::transaction(function () use ($user) {
            $cart = Cart::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $cart) {
                throw ValidationException::withMessages([
                    'cart' => 'Your cart is empty.',
                ]);
            }

            $pending = PendingCheckout::where('cart_id', $cart->id)->first();
            if ($pending) {
                return $pending;
            }

            $cartItems = $cart->items()
                // Keep products available while saving the immutable checkout snapshot.
                ->with(['product' => fn ($products) => $products->sharedLock()])
                ->lockForUpdate()
                ->get();

            if ($cartItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => 'Your cart is empty.',
                ]);
            }

            $orderItems = [];
            $totalCents = 0;

            foreach ($cartItems as $cartItem) {
                $product = $cartItem->product;

                if (! $product) {
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

                $orderItems[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => number_format($unitPriceCents / 100, 2, '.', ''),
                    'quantity' => $quantity,
                    'subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
                ];
            }

            return PendingCheckout::create([
                'reservation_id' => (string) Str::uuid(),
                'cart_id' => $cart->id,
                'order_items' => $orderItems,
                'total_amount' => number_format($totalCents / 100, 2, '.', ''),
            ]);
        });

        return $this->finishCheckout($checkout->reservation_id, $user);
    }

    public function finishCheckout(string $reservationId, ?User $actor = null): Order
    {
        // A missing intent is a business decision even when list reads may lag.
        // Keep this preliminary lookup outside the transaction: an ordinary read
        // inside it would establish a REPEATABLE READ snapshot before cart locking,
        // hiding an order just completed by the worker we waited for.
        $checkout = PendingCheckout::useWritePdo()->find($reservationId);
        $result = DB::transaction(function () use ($reservationId, $checkout, $actor) {
            if (! $checkout) {
                // Another worker may have finished this exact operation already.
                return Order::with(['items', 'statusHistory'])->where('reservation_id', $reservationId)->first()
                    ?? new InventoryRejectedException('Checkout was rejected. Please retry checkout.');
            }

            // All checkout and cart-write paths lock the cart first.
            $cart = Cart::lockForUpdate()->findOrFail($checkout->cart_id);
            $checkout = PendingCheckout::lockForUpdate()->find($reservationId);
            if (! $checkout) {
                return Order::with(['items', 'statusHistory'])->where('reservation_id', $reservationId)->first()
                    ?? new InventoryRejectedException('Checkout was rejected. Please retry checkout.');
            }

            try {
                $this->inventoryClient->reserveStock($reservationId, array_map(
                    fn (array $item) => ['product_id' => $item['product_id'], 'quantity' => $item['quantity']],
                    $checkout->order_items,
                ));
            } catch (InventoryRejectedException $exception) {
                // A definitive business rejection has no stock effect. Commit removal
                // of the intent before surfacing the error so the cart is editable.
                $checkout->delete();

                return $exception;
            }

            // Communication and local persistence errors leave the durable intent
            // intact. Retrying ReserveStock with this ID has no second stock effect.
            $order = new Order([
                'user_id' => $cart->user_id,
                'status' => 'pending',
                'total_amount' => $checkout->total_amount,
                'reservation_id' => $reservationId,
            ]);
            $order->withStatusActor($actor)->save();
            $order->items()->createMany($checkout->order_items);
            CartItem::where('cart_id', $cart->id)->delete();
            $checkout->delete();

            return $order->load(['items', 'statusHistory']);
        });

        if ($result instanceof InventoryRejectedException) {
            throw $result;
        }

        return $result;
    }

    public function cancelUserOrder(User $user, int $orderId): Order
    {
        $lockedOrder = fn () => Order::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->findOrFail($orderId);

        // Persist the intent before releasing stock. If the RPC response is lost,
        // retries can finish the cancellation while confirmation stays blocked.
        DB::transaction(function () use ($lockedOrder, $user) {
            $order = $lockedOrder();
            if (! in_array($order->status, ['pending', Order::CANCELLING, 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending orders can be cancelled.',
                ]);
            }

            if ($order->status === 'pending') {
                $this->reservationId($order);
                $order->withStatusActor($user)->update(['status' => Order::CANCELLING]);
            }
        });

        return DB::transaction(function () use ($lockedOrder, $user) {
            $order = $lockedOrder();

            return $order->status === 'cancelled'
                ? $order->load(['items', 'statusHistory'])
                : $this->applyStatus($order, 'cancelled', $user);
        });
    }

    public function updateStatus(int $orderId, string $status, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($orderId, $status, $actor) {
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

            return $this->applyStatus($order, $status, $actor);
        });
    }

    private function applyStatus(Order $order, string $status, ?User $actor = null): Order
    {
        if ($status === 'shipped') {
            $this->inventoryClient->commitReservation($this->reservationId($order));
        } elseif ($status === 'cancelled') {
            $this->inventoryClient->releaseStock($this->reservationId($order));
        }

        $order->withStatusActor($actor)->update(['status' => $status]);

        return $order->load(['items', 'statusHistory']);
    }

    private function reservationId(Order $order): string
    {
        abort_if(! $order->reservation_id, 422, 'Order does not have an inventory reservation.');

        return $order->reservation_id;
    }
}
