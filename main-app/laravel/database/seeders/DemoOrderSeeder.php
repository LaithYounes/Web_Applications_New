<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Products\Models\Product;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoOrderSeeder extends Seeder
{
    private const ORDERS = [
        'daniel.brooks@example.test' => [
            ['iphone15' => 1, 'ankerNano' => 2],
            [],
        ],
        'olivia.bennett@example.test' => [
            ['logitechM185' => 1],
            ['confirmed'],
        ],
        'sophie.miller@example.test' => [
            ['ideapad' => 1],
            ['confirmed', 'processing'],
        ],
        'james.turner@example.test' => [
            ['jblFlip6' => 1],
            ['confirmed', 'processing', 'shipped'],
        ],
        'grace.wilson@example.test' => [
            ['galaxyA55' => 1],
            ['confirmed', 'processing', 'shipped', 'delivered'],
        ],
        'ethan.parker@example.test' => [
            ['philipsAirfryer' => 1],
            ['cancelled'],
        ],
    ];

    private const CARTS = [
        'daniel.brooks@example.test' => ['sonyHeadphones' => 1],
        'olivia.bennett@example.test' => ['sonyHeadphones' => 1],
        'mia.collins@example.test' => ['galaxyA55' => 1, 'ankerNano' => 1],
        'noah.reed@example.test' => ['redmiNote13' => 1, 'soundcoreQ45' => 1],
        'ava.morgan@example.test' => [],
    ];

    public function run(OrderService $orders): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Demo orders can only be seeded in local or testing environments.');
        }

        foreach (self::ORDERS as $email => [$products, $transitions]) {
            $user = User::where('email', $email)->firstOrFail();
            $quantities = $this->productQuantities($products);
            $order = Order::where('user_id', $user->id)
                ->with('items')
                ->oldest('id')
                ->get()
                ->first(fn (Order $order) => $order->items
                    ->pluck('quantity', 'product_id')
                    ->map(fn ($quantity) => (int) $quantity)
                    ->sortKeys()
                    ->all() === $quantities);

            if ($order === null) {
                $cart = Cart::firstOrCreate(['user_id' => $user->id]);
                $existing = $cart->items()->pluck('quantity', 'product_id')->all();
                if (array_diff_assoc($existing, $quantities) !== []) {
                    throw new RuntimeException("The cart for {$email} contains changes outside the demo order.");
                }

                $this->fillCart($cart, $quantities);
                $order = $orders->createOrder($user);
            }

            // Resume an interrupted seed without moving a manually progressed order backwards.
            $position = array_search($order->status, ['pending', ...$transitions], true);
            if ($position !== false) {
                foreach (array_slice($transitions, $position) as $status) {
                    $orders->updateStatus($order->id, $status);
                }
            }
        }

        foreach (self::CARTS as $email => $products) {
            $user = User::where('email', $email)->firstOrFail();
            $cart = Cart::firstOrCreate(['user_id' => $user->id]);
            $this->fillCart($cart, $this->productQuantities($products));
        }
    }

    private function productQuantities(array $products): array
    {
        $quantities = [];
        foreach ($products as $identifier => $quantity) {
            $product = Product::published()->where('creation_key', DemoCatalogSeeder::creationKey($identifier))->firstOrFail();
            $quantities[$product->id] = $quantity;
        }

        ksort($quantities);

        return $quantities;
    }

    private function fillCart(Cart $cart, array $quantities): void
    {
        foreach ($quantities as $productId => $quantity) {
            $cart->items()->firstOrCreate(['product_id' => $productId], ['quantity' => $quantity]);
        }
    }
}
