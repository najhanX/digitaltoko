<?php

namespace App\Support;

use App\Models\CatalogProduct;
use App\Models\Store;

class StorefrontDesign
{
    public const SECTIONS = ['banner', 'benefits', 'flash_sale', 'trending', 'catalog', 'leaderboard', 'guide'];

    public static function sectionKeys(string $template): array
    {
        return $template === 'aurora' ? ['quick_order', ...self::SECTIONS] : self::SECTIONS;
    }

    private function sections(string $template, array $saved = []): array
    {
        $keys = self::sectionKeys($template);
        $sections = array_values(array_filter($saved, fn ($section) => in_array($section['key'], $keys, true)));
        if ($template === 'aurora' && ! in_array('quick_order', array_column($sections, 'key'), true)) {
            array_unshift($sections, ['key' => 'quick_order', 'enabled' => true, 'title' => '']);
        }
        foreach ($keys as $key) {
            if (! in_array($key, array_column($sections, 'key'), true)) {
                $sections[] = ['key' => $key, 'enabled' => true, 'title' => ''];
            }
        }

        return $sections;
    }

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
            $design['sections'] = $this->sections($store->template, $design['sections'] ?? []);
            $design['colors'] = array_replace($this->colors($store->template), $design['colors'] ?? []);
            $design['carousel'] = array_replace(['autoplay' => true, 'interval' => 5], $design['carousel'] ?? []);
            $design['trending_category_ids'] ??= CatalogProduct::where('store_id', $store->id)->whereIn('id', $design['trending_product_ids'] ?? [])->pluck('category_id')->unique()->values()->all();

            return [...$this->engagementDefaults(), 'favicon' => null, 'flash_discounts' => [], ...$design];
        }

        return [...$this->engagementDefaults(), 'accent' => '#9c80f6', 'logo' => null, 'favicon' => null,
            'colors' => $this->colors($store->template), 'carousel' => ['autoplay' => true, 'interval' => 5],
            'banners' => [['id' => 'default-banner', 'title' => $branding['gaming_banner_title'] ?? (in_array($store->template, ['nebula', 'promo'], true) ? 'Upgrade permainan. Taklukkan arena.' : 'Kebutuhan digital, satu tempat.'),
                'subtitle' => $branding['gaming_banner_subtitle'] ?? (in_array($store->template, ['nebula', 'promo'], true) ? 'Diamond, UC, dan voucher game favoritmu. Pilih nominal, isi ID, lalu lanjut bermain.' : 'Temukan produk digital pilihan, lengkapi tujuan, dan pantau pesanan dengan mudah.'),
                'image' => $branding['gaming_banner_image'] ?? null, 'href' => '#catalog', 'button' => 'Top up sekarang', 'enabled' => true, 'overlay' => true]],
            'flash_discounts' => [], 'flash_product_ids' => CatalogProduct::where('store_id', $store->id)->where('active', true)
                ->where('category', $branding['gaming_flash_category'] ?? 'Flash Sale')->orderBy('name')->orderBy('id')->limit(8)->pluck('id')->all(),
            'flash_starts_at' => null, 'flash_ends_at' => $branding['gaming_flash_ends_at'] ?? null,
            'trending_mode' => 'automatic', 'trending_product_ids' => [], 'trending_category_ids' => [],
            'sections' => $this->sections($store->template)];
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
        $urls = [$design['favicon'] ?? null, $design['logo'] ?? null, $design['welcome_ad']['image'] ?? null, ...array_column($design['banners'] ?? [], 'image')];
        $files = [];
        foreach ($urls as $url) {
            if (is_string($url) && preg_match('#^/storefront-media/'.preg_quote($storeId, '#').'/([a-zA-Z0-9_-]{20,80}\.(?:jpg|jpeg|png|webp))$#', $url, $match)) {
                $files[] = 'storefront/'.$storeId.'/'.$match[1];
            }
        }

        return array_values(array_unique($files));
    }
}
