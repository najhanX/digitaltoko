<?php

namespace App\Services;

use App\Models\CatalogProduct;
use App\Models\Store;

class FlashSalePricing
{
    public function price(Store $store, CatalogProduct $product, int $regular): int
    {
        $design = $store->branding['design'] ?? [];
        $rule = $design['flash_discounts'][$product->id] ?? null;
        if (! $rule || ! in_array($product->id, $design['flash_product_ids'] ?? [], true) || $product->kind === 'postpaid') {
            return $regular;
        }
        if (! empty($design['flash_starts_at']) && now()->lt($design['flash_starts_at'])) {
            return $regular;
        }
        if (! empty($design['flash_ends_at']) && now()->gte($design['flash_ends_at'])) {
            return $regular;
        }
        if (! collect($design['sections'] ?? [])->contains(fn ($section) => $section['key'] === 'flash_sale' && $section['enabled'])) {
            return $regular;
        }
        $discount = $rule['type'] === 'percent' ? intdiv($regular * (int) round((float) $rule['value'] * 100), 10000) : (int) $rule['value'];

        return max(0, $regular - $discount);
    }
}
