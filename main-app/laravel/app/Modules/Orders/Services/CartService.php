<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Orders\Models\Cart;
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
        return DB::transaction(function () use ($user, $data) {
            $cart = Cart::firstOrCreate(['user_id' => $user->id]);

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
        $cart = Cart::where('user_id', $user->id)->firstOrFail();

        $item = $cart->items()->findOrFail($itemId);
        $item->update(['quantity' => $quantity]);

        return $cart->load('items.product.category');
    }

    public function removeItem(User $user, int $itemId): Cart
    {
        $cart = Cart::where('user_id', $user->id)->firstOrFail();

        $cart->items()->findOrFail($itemId)->delete();

        return $cart->load('items.product.category');
    }
}
