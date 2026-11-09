<?php

use App\Modules\Orders\Controllers\CartController;
use App\Modules\Orders\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->group(function () {

    // Cart endpoints
    Route::get('/cart', [CartController::class, 'show']);
    Route::post('/cart/items', [CartController::class, 'add']);

    Route::put('/cart/items/{itemId}', [CartController::class, 'update'])
        ->whereNumber('itemId');

    Route::delete('/cart/items/{itemId}', [CartController::class, 'remove'])
        ->whereNumber('itemId');

    // Orders endpoints - authenticated users
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders', [OrderController::class, 'index']);

    Route::get('/orders/{id}', [OrderController::class, 'show'])
        ->whereNumber('id');

    // Admin only
    Route::middleware('role:admin')->group(function () {
        Route::patch('/orders/{id}/status', [OrderController::class, 'updateStatus'])
            ->whereNumber('id');
    });

});