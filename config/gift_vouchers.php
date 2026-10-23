<?php

return [
    'enabled' => env('GIFT_VOUCHERS_ENABLED', true),
    'min_amount' => 10,
    'max_amount' => 300,
    'amount_step' => 10,
    'default_amount' => 30,
    'code_prefix' => env('GIFT_VOUCHER_CODE_PREFIX', 'LIBER-'),
    'code_length' => 16,
    'reservation_minutes' => 180,
    'payment_codes' => ['bank', 'bank_transfer', 'wspay', 'ws_pay', 'corvus', 'corvus_wallets', 'corvuspay', 'kekspay', 'keks'],
    'emails_enabled' => env('GIFT_VOUCHER_EMAILS_ENABLED', env('APP_ENV') === 'production'),
    'batch_size' => 50,
    'max_email_attempts' => 5,
];
