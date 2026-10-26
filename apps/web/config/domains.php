<?php

return [
    'cname_target' => env('CUSTOM_DOMAIN_CNAME_TARGET'),
    'origin_ips' => array_values(array_filter(array_map('trim', explode(',', env('CUSTOM_DOMAIN_ORIGIN_IPS', ''))))),
    'tls_ask_key' => env('CUSTOM_DOMAIN_TLS_ASK_KEY', ''),
];
