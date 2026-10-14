<?php

return [
    'cache_store' => env('SUPPLIER_CACHE_STORE', 'redis'),
    'cache_seconds' => (int) env('SUPPLIER_CACHE_SECONDS', 120),
    'max_snapshot_age' => (int) env('SUPPLIER_MAX_SNAPSHOT_AGE', 600),
];
