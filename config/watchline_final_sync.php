<?php

return [
    // A separately restored, immutable LOCAL snapshot. Connection credentials
    // and read-only options are inherited from the original baseline alias.
    'fresh_database' => env('WATCHLINE_FINAL_SOURCE_DATABASE'),
];
