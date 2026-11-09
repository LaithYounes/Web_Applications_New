<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'message' => 'API is running successfully',
            'data' => ['status' => 'ok'],
        ]);
    });

    require app_path('Modules/Users/Routes/api.php');
    require app_path('Modules/Products/Routes/api.php');
    require app_path('Modules/Orders/Routes/api.php');
    require app_path('Modules/Notifications/Routes/api.php');
});
