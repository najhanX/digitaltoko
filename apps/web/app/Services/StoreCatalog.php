<?php

namespace App\Services;

use App\Models\CatalogProduct;
use App\Models\Store;
use App\Support\CatalogAccess;
use Illuminate\Database\Eloquent\Builder;

class StoreCatalog
{
    public function __construct(private ProductPricing $pricing) {}

    public function query(Store $store, bool $refreshSuppliers = true): Builder
    {
        if ($refreshSuppliers) {
            app(SupplierCatalog::class)->refreshForStore($store);
        }

        return CatalogProduct::where('store_id', $store->id)->with(['mappings.item.connection', 'taxonomyCategory'])
            ->withCount('readyStocks')->withMin('readyStocks', 'cost');
    }

    public function present(CatalogProduct $product, Store $store, bool $private = false): array
    {
        $candidates = [];
        $staleSupplier = false;
        foreach ($product->mappings as $mapping) {
            if (! $mapping->active || $mapping->store_id !== $store->id || ! CatalogAccess::supports($store, $mapping->source)) {
                continue;
            }
            if ($mapping->source === 'stock') {
                if ($product->kind === 'premium' && ($store->sandbox || $product->ready_stocks_count > 0)) {
                    $candidates[] = ['mapping_id' => $mapping->id, 'source' => 'stock', 'cost' => (int) ($product->ready_stocks_min_cost ?? ($store->sandbox ? $product->sale_price : 0)), 'quantity' => $store->sandbox ? null : (int) $product->ready_stocks_count];
                }
            } else {
                $item = $mapping->item;
                $connection = $item?->connection;
                if ($item && $item->store_id === $store->id && $item->kind === $product->kind && $connection?->enabled
                    && $connection->store_id === $store->id && $connection->kind === $mapping->source && $item->available) {
                    if ($item->observed_at->lt(now()->subSeconds((int) config('suppliers.max_snapshot_age', 600)))) {
                        $staleSupplier = true;

                        continue;
                    }
                    $candidates[] = ['mapping_id' => $mapping->id, 'source' => $mapping->source, 'cost' => $item->cost, 'quantity' => $item->quantity];
                }
            }
        }
        usort($candidates, fn ($a, $b) => [$a['cost'], $a['mapping_id']] <=> [$b['cost'], $b['mapping_id']]);
        $cost = $candidates[0]['cost'] ?? null;
        $rule = $this->pricing->resolve($product);
        $price = $cost !== null && $rule['auto_update'] ? $this->pricing->apply($cost, $rule['markup']) : $product->sale_price;
        $regular = $price;
        $price = app(FlashSalePricing::class)->price($store, $product, $regular);
        $staleSupplier = $staleSupplier && count($candidates) === 0;
        $quantity = count($candidates) ? (in_array(null, array_column($candidates, 'quantity'), true) ? null : array_sum(array_column($candidates, 'quantity'))) : ($staleSupplier ? null : 0);
        $available = $product->active && count($candidates) > 0 && $price > 0 && $price >= $cost && $store->acceptsOrders();
        $public = [...$product->only(['id', 'code', 'name', 'description', 'kind', 'service', 'category', 'category_id']),
            'regular_price' => $regular, 'compare_price' => $price < $regular ? $regular : null, 'discount_amount' => $regular - $price,
            'category_image' => $store->branding['category_images'][$product->category_id] ?? null, 'price' => $price, 'available' => $available,
            'quantity' => $quantity, 'supplier_stale' => $staleSupplier, 'price_label' => $product->kind === 'postpaid' ? 'Biaya admin' : 'Harga'];
        if (! $private) {
            return $public;
        }

        return [...$public, 'category_id' => $product->category_id, 'service_id' => $product->taxonomyCategory?->service_id, ...$product->only(['active', 'sale_price', 'markup', 'auto_update']), 'cost' => $cost, 'rule' => $rule, 'candidates' => $candidates,
            'mapping_ids' => $product->mappings->where('active', true)->pluck('supplier_product_id')->filter()->values(),
            'mapping_details' => $product->mappings->where('active', true)->filter(fn ($mapping) => $mapping->item)->map(fn ($mapping) => [...$mapping->item->only(['id', 'name', 'sku', 'mode', 'cost', 'kind']), 'source' => $mapping->source, 'allowed' => CatalogAccess::supports($store, $mapping->source)])->values(),
            'use_stock' => $product->mappings->where('source', 'stock')->where('active', true)->isNotEmpty(),
            'ready_stock' => (int) $product->ready_stocks_count, 'price_blocked' => $cost !== null && $price < $cost];
    }

    public function reprice(Store $store): void
    {
        if ($store->sandbox) {
            return;
        }
        $this->pricing->forget();
        $this->query($store, false)->chunkById(100, function ($products) use ($store) {
            foreach ($products as $product) {
                $quote = $this->present($product, $store, true);
                if ($quote['rule']['auto_update'] && $quote['cost'] !== null && $product->sale_price !== $quote['regular_price']) {
                    $product->update(['sale_price' => $quote['regular_price']]);
                }
            }
        });
    }
}
