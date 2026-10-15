<?php

namespace App\Services;

use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\PricingRule;
use Illuminate\Validation\ValidationException;

class CatalogTaxonomy
{
    public function assign(CatalogProduct $product): void
    {
        if (! $product->isDirty(['service', 'category', 'category_id']) && $product->category_id) {
            return;
        }
        if ($product->category_id && $product->isDirty('category_id')) {
            $category = CatalogCategory::with('service')->where('store_id', $product->store_id)->findOrFail($product->category_id);
            $product->service = $category->service->name;
            $product->category = $category->name;

            return;
        }
        $service = CatalogService::firstOrCreate(['store_id' => $product->store_id, 'name_key' => mb_strtolower(trim($product->service))], ['name' => trim($product->service)]);
        $category = CatalogCategory::firstOrCreate(['service_id' => $service->id, 'name_key' => mb_strtolower(trim($product->category))], ['store_id' => $product->store_id, 'name' => trim($product->category)]);
        $product->category_id = $category->id;
        $product->service = $service->name;
        $product->category = $category->name;
    }

    public function preserveRule(string $storeId, string $scope, string $oldName, string $newName, bool $keepOld = false): void
    {
        $oldKey = mb_strtolower(trim($oldName));
        $newKey = mb_strtolower(trim($newName));
        if ($oldKey === $newKey) {
            return;
        }
        if (PricingRule::where('store_id', $storeId)->where('scope', $scope)->where('key', $newKey)->exists()) {
            throw ValidationException::withMessages(['name' => 'Nama baru sudah memiliki aturan harga. Selesaikan aturan harga tersebut terlebih dahulu agar harga produk tidak berubah tanpa sengaja.']);
        }
        $rule = PricingRule::where('store_id', $storeId)->where('scope', $scope)->where('key', $oldKey)->first();
        if ($rule) {
            if ($keepOld) {
                PricingRule::create([...$rule->only(['store_id', 'scope', 'markup', 'auto_update']), 'key' => $newKey]);
            } else {
                $rule->update(['key' => $newKey]);
            }
        }
    }
}
