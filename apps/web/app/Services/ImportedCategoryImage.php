<?php

namespace App\Services;

use App\Models\CatalogCategory;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportedCategoryImage
{
    public function resolve(string $category): ?string
    {
        $name = preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower($category)));
        $matches = [];
        foreach (config('category_logos', []) as $logo) {
            foreach ($logo['aliases'] as $alias) {
                if (preg_match('/(?<![a-z0-9])'.preg_quote($alias, '/').'(?![a-z0-9])/', $name)) {
                    $matches[$logo['asset']] = true;
                }
            }
        }
        if (count($matches) !== 1) {
            return null;
        }
        $path = '/brand/catalog/'.array_key_first($matches);

        return is_file(public_path($path)) ? $path : null;
    }

    public function backfill(Store $store, bool $apply = false): int
    {
        return DB::transaction(function () use ($store, $apply) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $branding = $locked->branding ?? [];
            $count = 0;
            $categories = CatalogCategory::where('store_id', $locked->id)
                ->whereHas('products', fn ($query) => $query->where('store_id', $locked->id)
                    ->whereHas('mappings', fn ($mapping) => $mapping->where('store_id', $locked->id)->whereNotNull('supplier_product_id')))->get(['id', 'name']);
            foreach ($categories as $category) {
                if (! empty($branding['category_images'][$category->id])
                    || in_array($branding['category_image_preferences'][$category->id] ?? null, ['manual', 'custom', 'none'], true)) {
                    continue;
                }
                $image = $this->resolve($category->name);
                if ($image) {
                    $branding['category_images'][$category->id] = $image;
                    $branding['category_image_preferences'][$category->id] = 'auto';
                    $count++;
                }
            }
            if ($apply && $count > 0) {
                $locked->update(['branding' => $branding]);
            }

            return $count;
        });
    }
}
