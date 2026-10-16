<?php

namespace App\Support;

use App\Models\CatalogProduct;
use App\Models\Store;

class StorefrontDesign
{
    public const SECTIONS = ['banner', 'benefits', 'flash_sale', 'trending', 'catalog', 'leaderboard', 'guide'];

    public function colors(string $template): array
    {
        return in_array($template, ['nebula', 'promo'], true)
            ? ['secondary' => '#f4b65b', 'background' => '#0c101c', 'surface' => '#171d2d', 'text' => '#f2f3fa', 'muted' => '#9ca5b9', 'border' => '#293143']
            : ['secondary' => '#0891b2', 'background' => $template === 'atlas' ? '#f5f7fb' : '#ffffff', 'surface' => '#ffffff', 'text' => '#17213a', 'muted' => '#718096', 'border' => '#e7eaf0'];
    }

    public function engagementDefaults(): array
    {
        return ['welcome_ad' => ['enabled' => false, 'title' => 'Promo pilihan untuk Anda', 'message' => 'Temukan penawaran dan produk pilihan toko kami.', 'image' => null, 'href' => '#flash-sale', 'button' => 'Lihat promo'],
            'customer_service' => ['enabled' => false, 'phone' => null, 'label' => 'Chat CS', 'message' => 'Halo, saya ingin bertanya tentang produk di toko ini.']];
    }

    public function resolve(Store $store): array
    {
        $branding = $store->branding ?? [];
        if (is_array($branding['design'] ?? null)) {
            $design = $branding['design'];
            $design['colors'] = array_replace($this->colors($store->template), $design['colors'] ?? []);
            $design['carousel'] = array_replace(['autoplay' => true, 'interval' => 5], $design['carousel'] ?? []);
            $design['trending_category_ids'] ??= CatalogProduct::where('store_id', $store->id)->whereIn('id', $design['trending_product_ids'] ?? [])->pluck('category_id')->unique()->values()->all();

            return [...$this->engagementDefaults(), ...$design];
        }

        return [...$this->engagementDefaults(), 'accent' => '#9c80f6', 'logo' => null,
            'colors' => $this->colors($store->template), 'carousel' => ['autoplay' => true, 'interval' => 5],
            'banners' => [['id' => 'default-banner', 'title' => $branding['gaming_banner_title'] ?? 'Upgrade permainan. Taklukkan arena.',
                'subtitle' => $branding['gaming_banner_subtitle'] ?? 'Diamond, UC, dan voucher game favoritmu. Pilih nominal, isi ID, lalu lanjut bermain.',
                'image' => $branding['gaming_banner_image'] ?? null, 'href' => '#catalog', 'button' => 'Top up sekarang', 'enabled' => true, 'overlay' => true]],
            'flash_product_ids' => CatalogProduct::where('store_id', $store->id)->where('active', true)
                ->where('category', $branding['gaming_flash_category'] ?? 'Flash Sale')->orderBy('name')->orderBy('id')->limit(8)->pluck('id')->all(),
            'flash_starts_at' => null, 'flash_ends_at' => $branding['gaming_flash_ends_at'] ?? null,
            'trending_mode' => 'automatic', 'trending_product_ids' => [], 'trending_category_ids' => [],
            'sections' => array_map(fn ($key) => ['key' => $key, 'enabled' => true, 'title' => ''], self::SECTIONS)];
    }

    public function editor(Store $store): array
    {
        $design = $this->resolve($store);
        $ids = array_unique([...$design['flash_product_ids'], ...$design['trending_product_ids']]);

        return ['design' => $design, 'designRevision' => (int) ($store->branding['design_revision'] ?? 0),
            'designProducts' => CatalogProduct::where('store_id', $store->id)->whereIn('id', $ids)->get(['id', 'name', 'code', 'category', 'sale_price', 'active'])];
    }

    public function assets(array $design, string $storeId): array
    {
        $urls = [$design['logo'] ?? null, $design['welcome_ad']['image'] ?? null, ...array_column($design['banners'] ?? [], 'image')];
        $files = [];
        foreach ($urls as $url) {
            if (is_string($url) && preg_match('#^/storefront-media/'.preg_quote($storeId, '#').'/([a-zA-Z0-9_-]{20,80}\.(?:jpg|jpeg|png|webp))$#', $url, $match)) {
                $files[] = 'storefront/'.$storeId.'/'.$match[1];
            }
        }

        return array_values(array_unique($files));
    }
}
