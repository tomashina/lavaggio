<?php

return [
    // Empty prices inherit the existing Lavaggio delivery tariff during initial setup.
    'gls_home_price' => env('LAVAGGIO_GLS_HOME_PRICE'),
    'gls_locker_price' => env('LAVAGGIO_GLS_LOCKER_PRICE'),
    'boxnow_price' => env('LAVAGGIO_BOXNOW_PRICE'),
    'boxnow_partner_id' => env('LAVAGGIO_BOXNOW_PARTNER_ID', ''),
    'bank' => [
        'receiver_name' => env('LAVAGGIO_BANK_RECEIVER_NAME', 'Bareda d.o.o.'),
        'receiver_street' => env('LAVAGGIO_BANK_RECEIVER_STREET', 'Barban 70'),
        'receiver_place' => env('LAVAGGIO_BANK_RECEIVER_PLACE', '52207 Barban'),
        'receiver_iban' => env('LAVAGGIO_BANK_IBAN', 'XXXX'),
    ],
];
