<?php

return [
    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', ''),
    'password' => env('RABBITMQ_PASSWORD', ''),
    'vhost' => env('RABBITMQ_VHOST', 'ecommerce'),

    'management_port' => 15672,

    'email_queue' => 'orders.email',
    'email_retry_queue' => 'orders.email.retry',
    'email_dlq' => 'orders.email.dlq',

    'email_max_attempts' => 5,
    'email_retry_delay_seconds' => 30,
];
