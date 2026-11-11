<?php

return [
    'driver' => env('ORDER_PUSH_DRIVER', 'disabled'),
    'project_id' => env('FIREBASE_PROJECT_ID'),
    'credentials' => env('FIREBASE_CREDENTIALS'),
    'timeout' => max(1, min(15, (int) env('ORDER_PUSH_TIMEOUT', 10))),
    'connect_timeout' => max(1, min(5, (int) env('ORDER_PUSH_CONNECT_TIMEOUT', 3))),
    'max_attempts' => max(1, min(10, (int) env('ORDER_PUSH_MAX_ATTEMPTS', 5))),
    'backoff' => max(10, (int) env('ORDER_PUSH_BACKOFF', 60)),
    // Local/testing only. Script outcomes without ever sending to Firebase.
    'fake_results' => array_filter(explode(',', env('ORDER_PUSH_FAKE_RESULTS', 'accepted'))),
];
