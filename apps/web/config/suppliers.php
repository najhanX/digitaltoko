<?php

return [
    'outbound_ip' => env('SUPPLIER_OUTBOUND_IP'),
    'digiflazz_outbound_ip' => env('DIGIFLAZZ_OUTBOUND_IP') ?: env('SUPPLIER_OUTBOUND_IP'),
    'digiflazz_webhook_url' => env('DIGIFLAZZ_WEBHOOK_URL'),
    'cache_store' => env('SUPPLIER_CACHE_STORE', 'redis'),
    'cache_seconds' => (int) env('SUPPLIER_CACHE_SECONDS', 120),
    'max_snapshot_age' => (int) env('SUPPLIER_MAX_SNAPSHOT_AGE', 600),
];
