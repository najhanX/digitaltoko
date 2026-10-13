<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

class ConfiguredTrustProxies extends TrustProxies
{
    protected function proxies(): array
    {
        return config('platform.trusted_proxies', []);
    }
}
