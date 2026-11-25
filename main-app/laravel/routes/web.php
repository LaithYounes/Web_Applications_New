<?php

use App\Http\Middleware\BrowserSession;
use App\Http\Middleware\StorefrontAccess;
use App\Http\Middleware\StorefrontHeaders;
use Illuminate\Support\Facades\Route;

require __DIR__.'/browser.php';

Route::middleware(StorefrontHeaders::class)->group(function () {
    Route::view('/', 'storefront');
    Route::view('/shop', 'storefront');
    Route::view('/products/{id}', 'storefront')->whereNumber('id');
    Route::view('/login', 'storefront')->name('login');
    Route::view('/register', 'storefront');

    Route::middleware(BrowserSession::class)->group(function () {
        Route::view('/account', 'storefront')->middleware(StorefrontAccess::class);
        Route::middleware(StorefrontAccess::class.':customer')->group(function () {
            Route::view('/cart', 'storefront');
            Route::view('/checkout', 'storefront');
            Route::view('/orders/{id?}', 'storefront')->whereNumber('id');
            Route::view('/notifications', 'storefront');
        });
        Route::view('/admin/{page?}', 'storefront')
            ->where('page', 'products(?:/(?:new|[0-9]+))?|categories|orders(?:/[0-9]+)?|order-email-failures(?:/[0-9]+)?')
            ->middleware(StorefrontAccess::class.':admin');
    });
});
