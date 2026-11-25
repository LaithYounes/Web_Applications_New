<?php

use App\Modules\Orders\Controllers\CartController;
use App\Modules\Orders\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

$browser ??= false;

Route::middleware('auth:api')->group(function () use ($browser) {

    // Keep the Android contract while limiting browser shopping to customers.
    Route::middleware(($browser ?? false) ? ['role:customer'] : [])->group(function () {
        Route::get('/cart', [CartController::class, 'show']);
        Route::post('/cart/items', [CartController::class, 'add']);

        Route::put('/cart/items/{itemId}', [CartController::class, 'update'])
            ->whereNumber('itemId');

        Route::delete('/cart/items/{itemId}', [CartController::class, 'remove'])
            ->whereNumber('itemId');

        Route::post('/orders', [OrderController::class, 'store']);
    });

    // Orders endpoints - authenticated users
    Route::get('/orders', [OrderController::class, 'index']);

    Route::get('/orders/{id}', [OrderController::class, 'show'])
        ->whereNumber('id');

    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])
        ->whereNumber('id')
        ->middleware('role:customer');

    // Admin only
    Route::middleware('role:admin')->group(function () {
        Route::patch('/orders/{id}/status', [OrderController::class, 'updateStatus'])
            ->whereNumber('id');
    });

});
