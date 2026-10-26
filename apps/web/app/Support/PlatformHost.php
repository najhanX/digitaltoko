<?php

namespace App\Support;

use App\Models\Store;
use App\Models\StoreDomain;

class PlatformHost
{
    public function domain(): string
    {
        return strtolower(rtrim((string) config('platform.domain'), '.'));
    }

    public function domains(): array
    {
        return array_values(array_unique([$this->domain(), ...array_map(fn ($host) => strtolower(rtrim($host, '.')), config('platform.domain_aliases', []))]));
    }

    public function isPlatform(string $host): bool
    {
        // artisan serve advertises 127.0.0.1, while APP_URL commonly uses localhost.
        // These aliases are only for local development, never production routing.
        if (app()->environment('local') && in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            return true;
        }

        return in_array(strtolower($host), $this->domains(), true);
    }

    public function resolveStore(string $host): ?Store
    {
        $host = strtolower($host);
        foreach ($this->domains() as $base) {
            $suffix = '.'.$base;
            if (str_ends_with($host, $suffix)) {
                $slug = substr($host, 0, -strlen($suffix));
                if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $slug)
                    || in_array($slug, config('platform.reserved_slugs'), true)) {
                    return null;
                }

                return Store::where('slug', $slug)->first();
            }
        }

        return StoreDomain::with('store')->where('hostname', $host)
            ->where('status', 'active')->whereNotNull('verified_at')->first()?->store;
    }

    public function storeUrl(Store $store): string
    {
        $url = parse_url((string) config('app.url'));
        $custom = $store->domain?->status === 'active' && $store->domain?->verified_at;
        $host = $custom
            ? $store->domain->hostname : $store->slug.'.'.$this->domain();

        return ($custom ? 'https' : ($url['scheme'] ?? 'https')).'://'.$host.(! $custom && isset($url['port']) ? ':'.$url['port'] : '');
    }
}
