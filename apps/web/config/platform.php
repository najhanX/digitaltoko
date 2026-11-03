<?php

return [
    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],
    'store_purge_enabled' => env('STORE_PURGE_ENABLED', false),
    'domain' => env('PLATFORM_DOMAIN') ?: parse_url(env('APP_URL', 'http://localhost:8000'), PHP_URL_HOST),
    'preview_enabled' => env('PLATFORM_PREVIEW_ENABLED', false),
    'domain_aliases' => array_values(array_filter(array_map('trim', explode(',', env('PLATFORM_DOMAIN_ALIASES', ''))))),
    'reserved_slugs' => ['www', 'panel', 'admin', 'api', 'staff', 'owner', 'mail', 'status', 'assets'],
    'trusted_proxies' => array_values(array_filter(explode(',', env('TRUSTED_PROXIES', '')))),
];
