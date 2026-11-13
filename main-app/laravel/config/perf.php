<?php

return [
    /*
    | Catalog Redis caching (MySQL fields only — never inventory quantities).
    */
    'catalog_cache_enabled' => (bool) env('PERF_CATALOG_CACHE', true),

    /*
    | HTTP ETag for product representations that include live stock.
    */
    'product_etag_enabled' => (bool) env('PERF_PRODUCT_ETAG', true),

    /*
    | Force order/cart reads onto the primary write PDO (cross-request safety).
    */
    'orders_use_primary' => (bool) env('PERF_ORDERS_USE_PRIMARY', true),
];
