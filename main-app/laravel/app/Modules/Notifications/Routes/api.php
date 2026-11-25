<?php

use App\Modules\Notifications\Controllers\DeviceController;
use App\Modules\Notifications\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;
use App\Modules\Notifications\Controllers\OrderEmailFailureController;

$browser ??= false;

Route::middleware(['auth:api', 'role:customer'])->group(function () use ($browser) {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id');
    if (! $browser) {
        Route::put('/notification-devices/{installation}', [DeviceController::class, 'store'])->whereUuid('installation');
        Route::delete('/notification-devices/{installation}', [DeviceController::class, 'destroy'])->whereUuid('installation');
    }
});
Route::prefix('admin')
    ->middleware(['auth:api', 'role:admin'])
    ->group(function () {
        Route::get(
            '/order-email-failures',
            [OrderEmailFailureController::class, 'index']
        );

        Route::get(
            '/order-email-failures/{id}',
            [OrderEmailFailureController::class, 'show']
        )->whereNumber('id');
    });
