<?php

use App\Http\Middleware\BrowserSession;
use App\Http\Middleware\ValidateBroadcastSubscription;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

// Mount the same API actions behind the web middleware (sessions and CSRF).
Route::prefix('api/browser')->middleware(BrowserSession::class)->group(function () {
    $browser = true;

    require app_path('Modules/Users/Routes/api.php');
    require app_path('Modules/Products/Routes/api.php');
    require app_path('Modules/Orders/Routes/api.php');
    require app_path('Modules/Notifications/Routes/api.php');

    Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate'])
        ->middleware(['auth:api', ValidateBroadcastSubscription::class, 'throttle:60,1']);
});
