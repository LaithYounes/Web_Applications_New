<?php

use App\Modules\Users\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {

    Route::post('/register', [AuthController::class, 'register']);

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::middleware('auth:api')->group(function () {

        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/me', [AuthController::class, 'me']);

    });
});
