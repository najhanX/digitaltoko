<?php

namespace App\Services;

use App\Models\CatalogProduct;
use App\Models\Store;
use App\Models\SupplierProduct;
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
            ->withCount('readyStocks')->withMin('readyStocks', 'cost')->withMax('readyStocks', 'cost');
    }

    public function present(CatalogProduct $product, Store $store, bool $private = false): array
    {
        $checkIds = $product->mappings->map(fn ($mapping) => $mapping->postpaid['inquiry_id'] ?? null)->filter()->unique()->values();
        $checks = $checkIds->isEmpty() ? collect() : SupplierProduct::where('store_id', $store->id)->whereIn('id', $checkIds)->get()->keyBy('id');
        $candidates = [];
        $staleSupplier = false;
        foreach ($product->mappings as $mapping) {
            if (! $mapping->active || $mapping->store_id !== $store->id || ! CatalogAccess::supports($store, $mapping->source)) {
                continue;
            }
            if ($mapping->source === 'stock') {
                if ($product->kind === 'premium' && ($store->sandbox || $product->ready_stocks_count > 0)) {
                    $candidates[] = ['mapping_id' => $mapping->id, 'source' => 'stock', 'cost' => (int) ($product->ready_stocks_min_cost ?? ($store->sandbox ? $product->sale_price : 0)), 'pricing_cost' => (int) ($product->ready_stocks_max_cost ?? ($store->sandbox ? $product->sale_price : 0)), 'quantity' => $store->sandbox ? null : (int) $product->ready_stocks_count];
                }
            } else {
                $item = $mapping->item;
                $connection = $item?->connection;
                if ($item && $item->store_id === $store->id && ($item->kind === $product->kind || ($product->kind === 'postpaid' && $mapping->source === 'otomax')) && $connection?->enabled
                    && $connection->store_id === $store->id && $connection->kind === $mapping->source && $item->available && ($item->quantity === null || $item->quantity > 0)) {
                    if ($item->observed_at->lt(now()->subSeconds(($connection->kind === 'otomax' ? max(1, min(168, (int) ($connection->settings['catalog_hours'] ?? 24))) * 3600 : (int) config('suppliers.max_snapshot_age', 600))))) {
                        $staleSupplier = true;

                        continue;
                    }
                    if ($product->kind === 'postpaid' && ! app(PostpaidBilling::class)->configured($mapping, $checks->get($mapping->postpaid['inquiry_id'] ?? ''))) {
                        continue;
                    }
                    $sourceCost = $product->kind === 'postpaid' && $mapping->source === 'otomax' ? $mapping->postpaid['admin'] : $item->cost;
                    $candidates[] = ['mapping_id' => $mapping->id, 'source' => $mapping->source, 'cost' => $sourceCost, ...($product->kind === 'postpaid' ? ['commission' => app(PostpaidBilling::class)->sourceAdjustment($mapping->source, $item->billing)] : []), 'quantity' => $item->quantity];
                }
            }
        }
        usort($candidates, fn ($a, $b) => [$a['cost'] + ($a['commission'] ?? 0), $a['mapping_id']] <=> [$b['cost'] + ($b['commission'] ?? 0), $b['mapping_id']]);
        $rule = $this->pricing->resolve($product);
        $cheapest = $rule['pricing_basis'] === 'cheapest';
        $basis = null;
        foreach ($candidates as $candidate) {
            $candidateCost = ($cheapest ? $candidate['cost'] : ($candidate['pricing_cost'] ?? $candidate['cost'])) + ($candidate['commission'] ?? 0);
            $basisCost = $basis === null ? null : ($cheapest ? $basis['cost'] : ($basis['pricing_cost'] ?? $basis['cost'])) + ($basis['commission'] ?? 0);
            if ($basis === null || ($cheapest ? $candidateCost < $basisCost : $candidateCost > $basisCost)) {
                $basis = $candidate;
            }
        }
        $cost = $basis === null ? null : ($cheapest ? $basis['cost'] : ($basis['pricing_cost'] ?? $basis['cost']));
        $price = $cost !== null && $rule['auto_update']
            ? ($product->kind === 'postpaid' ? $cost + ($basis['commission'] ?? 0) + app(PostpaidBilling::class)->storeFee($basis['commission'] ?? 0, $rule['markup']) : $this->pricing->apply($cost, $rule['markup']))
            : $product->sale_price;
        $regular = $price;
        $price = app(FlashSalePricing::class)->price($store, $product, $regular);
        $staleSupplier = $staleSupplier && count($candidates) === 0;
        $quantity = count($candidates) ? (in_array(null, array_column($candidates, 'quantity'), true) ? null : array_sum(array_column($candidates, 'quantity'))) : ($staleSupplier ? null : 0);
        $minimum = $candidates ? $candidates[0]['cost'] + ($candidates[0]['commission'] ?? 0) : null;
        $available = $product->active && count($candidates) > 0 && ($price > 0 || $product->kind === 'postpaid') && $price >= $minimum && $store->acceptsOrders();
        $public = [...$product->only(['id', 'code', 'name', 'description', 'kind', 'service', 'category', 'category_id']),
            'regular_price' => $regular, 'compare_price' => $price < $regular ? $regular : null, 'discount_amount' => $regular - $price,
            'category_image' => $store->branding['category_images'][$product->category_id] ?? null, 'price' => $price, 'available' => $available,
            'quantity' => $quantity, 'supplier_stale' => $staleSupplier, 'postpaid_fees' => $product->kind === 'postpaid' && $cost !== null && $price >= $minimum ? ['service_admin' => $cost, 'store_admin' => $price - $cost] : null, 'price_label' => $product->kind === 'postpaid' ? 'Total biaya admin' : 'Harga'];
        if (! $private) {
            return $public;
        }

        return [...$public, 'category_id' => $product->category_id, 'service_id' => $product->taxonomyCategory?->service_id, ...$product->only(['active', 'sale_price', 'markup', 'auto_update']), 'pricing_basis' => $rule['pricing_basis'], 'cost' => $cost, 'postpaid_commission' => $product->kind === 'postpaid' ? ($basis['commission'] ?? null) : null, 'rule' => $rule, 'candidates' => $candidates,
            'mapping_ids' => $product->mappings->where('active', true)->pluck('supplier_product_id')->filter()->values(),
            'mapping_details' => $product->mappings->where('active', true)->filter(fn ($mapping) => $mapping->item)->map(fn ($mapping) => [...$mapping->item->only(['id', 'name', 'sku', 'mode', 'cost', 'kind', 'billing']), 'connection_id' => $mapping->item->connection_id, 'postpaid' => $mapping->postpaid, 'inquiry_item' => $mapping->postpaid ? $checks->get($mapping->postpaid['inquiry_id'] ?? '')?->only(['id', 'name', 'sku', 'cost']) : null, 'source' => $mapping->source, 'allowed' => CatalogAccess::supports($store, $mapping->source)])->values(),
            'use_stock' => $product->mappings->where('source', 'stock')->where('active', true)->isNotEmpty(),
            'ready_stock' => (int) $product->ready_stocks_count, 'price_blocked' => $cost !== null && $price < $minimum];
    }

    public function reprice(Store $store, ?string $supplierProductId = null): void
    {
        if ($store->sandbox) {
            return;
        }
        $this->pricing->forget();
        $this->query($store, false)->when($supplierProductId, fn ($query) => $query->whereHas('mappings', fn ($mapping) => $mapping->where('supplier_product_id', $supplierProductId)))->chunkById(100, function ($products) use ($store) {
            foreach ($products as $product) {
                $quote = $this->present($product, $store, true);
                if ($quote['rule']['auto_update'] && $quote['cost'] !== null && $product->sale_price !== $quote['regular_price']) {
                    $product->update(['sale_price' => $quote['regular_price']]);
                }
            }
        });
    }
}
