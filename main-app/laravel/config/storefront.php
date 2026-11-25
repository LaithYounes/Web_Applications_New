<?php

return [
    'brand' => env('STOREFRONT_BRAND', 'Everyday Tech'),
    'currency' => env('STOREFRONT_CURRENCY'),
    'api_origin' => env('STOREFRONT_API_ORIGIN', ''),
    'storage_origin' => env('STOREFRONT_STORAGE_ORIGIN', ''),
    'reverb' => [
        'key' => env('REVERB_APP_KEY'),
        'url' => env('STOREFRONT_REVERB_URL', env('APP_ENV') === 'local' && env('BROADCAST_CONNECTION') === 'reverb'
            ? 'ws://127.0.0.1:8080'
            : null),
    ],
];
