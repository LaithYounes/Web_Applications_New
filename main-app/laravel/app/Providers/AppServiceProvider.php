<?php

namespace App\Providers;

use App\Modules\Notifications\Services\OrderNotificationRecorder;
use App\Support\Performance\CatalogCache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OrderNotificationRecorder::class);
        $this->app->singleton(CatalogCache::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
