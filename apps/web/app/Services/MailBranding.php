<?php

namespace App\Services;

use App\Models\Store;
use App\Support\PlatformHost;
use App\Support\StorefrontDesign;
use Illuminate\Support\Facades\Storage;

class MailBranding
{
    public function platform(): array
    {
        return ['name' => 'DigitalToko', 'primary' => '#4f46e5', 'on_primary' => '#ffffff', 'background' => '#f3f5fc',
            'surface' => '#ffffff', 'text' => '#17213a', 'muted' => '#64748b', 'border' => '#e2e8f0',
            'url' => rtrim(config('app.url'), '/'), 'logo' => rtrim(config('app.url'), '/').'/brand/digitaltoko-logo-color-v1.png',
            'logo_path' => public_path('brand/digitaltoko-logo-color-v1.png'), 'store' => false];
    }

    private function color(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/iD', $value) ? $value : $fallback;
    }

    public function store(Store $store): array
    {
        $url = app(PlatformHost::class)->storeUrl($store);
        $design = $store->branding['design'] ?? [];
        $colors = array_replace(app(StorefrontDesign::class)->colors($store->template), $design['colors'] ?? []);
        $brand = ['name' => $store->name, 'url' => $url, 'store' => true, 'logo' => null, 'logo_path' => null,
            'primary' => $this->color($design['accent'] ?? null, '#4f46e5')];
        foreach (['background' => '#f3f5fc', 'surface' => '#ffffff', 'text' => '#17213a', 'muted' => '#64748b', 'border' => '#e2e8f0'] as $key => $fallback) {
            $brand[$key] = $this->color($colors[$key] ?? null, $fallback);
        }
        $rgb = array_map(fn ($offset) => hexdec(substr($brand['primary'], $offset, 2)) / 255, [1, 3, 5]);
        $linear = array_map(fn ($value) => $value <= .04045 ? $value / 12.92 : (($value + .055) / 1.055) ** 2.4, $rgb);
        $brand['on_primary'] = .2126 * $linear[0] + .7152 * $linear[1] + .0722 * $linear[2] > .179 ? '#17213a' : '#ffffff';
        $logo = $design['logo'] ?? null;
        if (is_string($logo) && preg_match('#^/storefront-media/'.preg_quote($store->id, '#').'/([a-zA-Z0-9_-]{20,80}\.(?:jpg|jpeg|png|webp))$#D', $logo, $match)) {
            $path = 'storefront/'.$store->id.'/'.$match[1];
            if (Storage::disk('local')->exists($path)) {
                $brand['logo_path'] = Storage::disk('local')->path($path);
                $brand['logo'] = rtrim(config('app.url'), '/').$logo;
            }
        } elseif (is_string($logo) && filter_var($logo, FILTER_VALIDATE_URL) && parse_url($logo, PHP_URL_SCHEME) === 'https' && ! parse_url($logo, PHP_URL_USER) && ! parse_url($logo, PHP_URL_PASS)) {
            $brand['logo'] = $logo;
        }

        return $brand;
    }
}
