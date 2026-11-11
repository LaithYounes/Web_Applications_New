<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Notifications\Models\NotificationDevice;
use App\Modules\Notifications\Services\DeviceRegistry;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Support\Str;

trait NotificationFixtures
{
    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    private function order(User $user): Order
    {
        return Order::create(['user_id' => $user->id, 'total_amount' => '12.50', 'reservation_id' => (string) Str::uuid()]);
    }

    private function device(User $user, string $token = 'test:token', ?string $session = null): NotificationDevice
    {
        return app(DeviceRegistry::class)->register($user, (string) Str::uuid(), [
            'token' => $token, 'platform' => 'android', 'expected_revision' => 0,
        ], $session ?? hash('sha256', (string) Str::uuid()));
    }

    private function basket(User $user): Cart
    {
        $product = Product::create([
            'category_id' => Category::create(['name' => 'Test'])->id,
            'name' => 'Test', 'price' => '12.50', 'image_path' => 'test.png',
        ]);
        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        return $cart;
    }

    private function jwt(User $user): string
    {
        app('auth')->forgetGuards();
        $token = auth('api')->login($user);
        app('auth')->forgetGuards();
        $this->withToken($token);

        return $token;
    }
}
