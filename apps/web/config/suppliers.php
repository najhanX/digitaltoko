<?php

return [
    'simulator' => [
        'enabled' => (bool) env('SUPPLIER_SIMULATOR_ENABLED', false),
        'digiflazz_development' => (bool) env('SUPPLIER_SIMULATOR_DIGIFLAZZ_DEVELOPMENT', false),
        'digiflazz_callback_url' => env('SUPPLIER_SIMULATOR_DIGIFLAZZ_CALLBACK_URL'),
        'origins' => ['a' => 'http://127.0.0.1:4101', 'b' => 'http://127.0.0.1:4102', 'c' => 'http://127.0.0.1:4103'],
        'control' => 'http://127.0.0.1:4100',
        'slugs' => ['lab-minimum', 'lab-maximum'],
    ],
    'outbound_ip' => env('SUPPLIER_OUTBOUND_IP'),
    'digiflazz_outbound_ip' => env('DIGIFLAZZ_OUTBOUND_IP') ?: env('SUPPLIER_OUTBOUND_IP'),
    'digiflazz_webhook_url' => env('DIGIFLAZZ_WEBHOOK_URL'),
    'cache_store' => env('SUPPLIER_CACHE_STORE', 'redis'),
    'cache_seconds' => (int) env('SUPPLIER_CACHE_SECONDS', 120),
    'max_snapshot_age' => (int) env('SUPPLIER_MAX_SNAPSHOT_AGE', 600),
];
