<?php

use App\Modules\Notifications\Controllers\DeviceController;
use App\Modules\Notifications\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'role:customer'])->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id');
    Route::put('/notification-devices/{installation}', [DeviceController::class, 'store'])->whereUuid('installation');
    Route::delete('/notification-devices/{installation}', [DeviceController::class, 'destroy'])->whereUuid('installation');
});
