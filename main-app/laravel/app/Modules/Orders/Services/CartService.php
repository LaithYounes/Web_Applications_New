<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Inventory\Exceptions\InventoryRejectedException;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\PendingCheckout;
use App\Modules\Products\Models\Product;
use Illuminate\Support\Facades\DB;

class CartService
{
    public function getCart(User $user): Cart
    {
        $cart = Cart::firstOrCreate(['user_id' => $user->id]);

        return $cart->load('items.product.category');
    }

    public function addItem(User $user, array $data): Cart
    {
        Cart::firstOrCreate(['user_id' => $user->id]);

        return DB::transaction(function () use ($user, $data) {
            $cart = $this->editableCart($user);

            Product::published()->findOrFail($data['product_id']);

            $item = $cart->items()
                ->where('product_id', $data['product_id'])
                ->first();

            if ($item) {
                $item->increment('quantity', $data['quantity']);
            } else {
                $cart->items()->create($data);
            }

            return $cart->load('items.product.category');
        });
    }

    public function updateItem(User $user, int $itemId, int $quantity): Cart
    {
        return DB::transaction(function () use ($user, $itemId, $quantity) {
            $cart = $this->editableCart($user);

            $item = $cart->items()->findOrFail($itemId);
            $item->update(['quantity' => $quantity]);

            return $cart->load('items.product.category');
        });
    }

    public function removeItem(User $user, int $itemId): Cart
    {
        return DB::transaction(function () use ($user, $itemId) {
            $cart = $this->editableCart($user);

            $cart->items()->findOrFail($itemId)->delete();

            return $cart->load('items.product.category');
        });
    }

    private function editableCart(User $user): Cart
    {
        $cart = Cart::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
        if (PendingCheckout::where('cart_id', $cart->id)->lockForUpdate()->first()) {
            throw new InventoryRejectedException(
                'Checkout is awaiting inventory confirmation. Check your orders or retry checkout.',
            );
        }

        return $cart;
    }
}
