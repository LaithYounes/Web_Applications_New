<?php

use App\Modules\Inventory\Exceptions\InventoryRejectedException;
use App\Modules\Inventory\Exceptions\InventoryServiceException;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('products:retry-initial-stock', function (ProductService $products): int {
    $failed = false;

    foreach (Product::whereNotNull('pending_initial_quantity')->lazyById() as $product) {
        try {
            $products->finishInitialStock($product);
        } catch (InventoryRejectedException | InventoryServiceException $exception) {
            $this->error("Product {$product->id}: {$exception->getMessage()}");
            $failed = true;
        }
    }

    return $failed ? 1 : 0;
})->purpose('Retry stock initialization for products awaiting publication');
