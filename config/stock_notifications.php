<?php

return [
    'enabled' => env('STOCK_NOTIFICATIONS_ENABLED', true),
    'emails_enabled' => env('STOCK_NOTIFICATIONS_EMAILS_ENABLED', env('APP_ENV') === 'production'),
    'batch_size' => (int) env('STOCK_NOTIFICATIONS_BATCH_SIZE', 50),
    'max_attempts' => 3,
    'retry_minutes' => 5,
];
