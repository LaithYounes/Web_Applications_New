<?php

use App\Modules\Products\Controllers\CategoryController;
use App\Modules\Products\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

// Public endpoints
Route::get('/categories', [CategoryController::class, 'index']);

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show'])
    ->whereNumber('id');

// Admin endpoints
Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::post('/products', [ProductController::class, 'store']);
});
