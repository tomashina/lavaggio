<?php

return [
    'version' => 1,
    'snapshot_path' => 'watchline/checkout-source.json',
    // The additional local GLS locker option uses the verified Croatian GLS tariff.
    'gls_locker' => ['enabled' => true, 'price' => 4.50, 'free_over' => 70.00],
];
