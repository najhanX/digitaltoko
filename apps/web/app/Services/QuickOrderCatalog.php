<?php

namespace App\Services;

use App\Models\CatalogCategory;
use App\Models\Store;

class QuickOrderCatalog
{
    public const SERVICES = ['pulsa', 'data', 'voucher'];

    public function providers(): array
    {
        return collect(config('mobile_providers'))->map(fn ($provider, $id) => ['id' => $id, 'name' => $provider['name'], 'prefixes' => $provider['prefixes']])->values()->all();
    }

    public function category(Store $store, CatalogCategory $category): ?array
    {
        if (app(CategoryDestination::class)->profile($store, $category, 'ppob')['type'] !== 'phone') {
            return null;
        }
        $service = $this->service($category->service->name);
        $provider = $store->branding['category_providers'][$category->id] ?? null;

        return $service && is_string($provider) && array_key_exists($provider, config('mobile_providers'))
            ? ['provider' => $provider, 'service' => $service] : null;
    }

    public function service(string $name): ?string
    {
        $key = preg_replace('/[\s_-]+/u', ' ', mb_strtolower(trim($name)));

        return match ($key) {
            'pulsa', 'pulsa reguler' => 'pulsa',
            'paket data', 'data', 'internet', 'paket internet' => 'data',
            'voucher data', 'voucher internet' => 'voucher',
            default => null,
        };
    }

    public function categoryIds(Store $store, string $provider, string $service): array
    {
        return CatalogCategory::where('store_id', $store->id)->with('service:id,name')
            ->whereHas('products', fn ($query) => $query->where('active', true)->where('kind', 'ppob'))
            ->get(['id', 'name', 'service_id', 'store_id'])
            ->filter(fn ($category) => $this->category($store, $category) === ['provider' => $provider, 'service' => $service])->pluck('id')->all();
    }
}
