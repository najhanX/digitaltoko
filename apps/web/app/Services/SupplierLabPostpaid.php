<?php

namespace App\Services;

use App\Models\CatalogProduct;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;

class SupplierLabPostpaid
{
    public function attach(): void
    {
        app(SupplierSimulator::class)->assertIsolated();
        if (! config('suppliers.simulator.digiflazz_development')) {
            throw new \RuntimeException('Aktifkan Digiflazz development lab terlebih dahulu.');
        }
        foreach (Store::whereIn('slug', config('suppliers.simulator.slugs'))->get() as $store) {
            if (! app(SupplierSimulator::class)->store($store)) {
                throw new \RuntimeException('Toko pascabayar lab harus sandbox.');
            }
            $d = SupplierConnection::where('store_id', $store->id)->where('kind', 'digiflazz')->where('slot', 'lab-digiflazz')->firstOrFail();
            $d->update(['synced_at' => now()]);
            foreach (['pln' => 'HPLN25', 'pdam' => 'HPDAM25'] as $sku => $pair) {
                $item = SupplierProduct::updateOrCreate(['store_id' => $store->id, 'connection_id' => $d->id, 'sku' => $sku, 'mode' => 'pasca'], ['name' => strtoupper($sku).' Development Admin 2.500', 'kind' => 'postpaid', 'service' => 'Pascabayar', 'category' => strtoupper($sku), 'cost' => 2500, 'billing' => ['admin' => 2500, 'commission' => 1000], 'available' => true, 'quantity' => null, 'observed_at' => now()]);
                $product = CatalogProduct::firstOrCreate(['store_id' => $store->id, 'code' => 'LAB-HYBRID-'.strtoupper($sku)], ['name' => strtoupper($sku).' · Digiflazz dev + A/B/C', 'kind' => 'postpaid', 'service' => 'Pascabayar', 'category' => strtoupper($sku).' Hybrid', 'sale_price' => 0, 'active' => true, 'auto_update' => true]);
                ProductMapping::firstOrCreate(['product_id' => $product->id, 'source_key' => 'item:'.$item->id], ['store_id' => $store->id, 'source' => 'digiflazz', 'supplier_product_id' => $item->id, 'active' => true]);
                foreach (SupplierConnection::where('store_id', $store->id)->where('kind', 'otomax')->get() as $o) {
                    if (! app(SupplierSimulator::class)->connection($o)) {
                        continue;
                    }
                    $pay = SupplierProduct::where('connection_id', $o->id)->where('sku', 'BYR'.$pair)->firstOrFail();
                    $check = SupplierProduct::where('connection_id', $o->id)->where('sku', 'CEK'.$pair)->firstOrFail();
                    ProductMapping::updateOrCreate(['product_id' => $product->id, 'source_key' => 'item:'.$pay->id], ['store_id' => $store->id, 'source' => 'otomax', 'supplier_product_id' => $pay->id, 'active' => true, 'postpaid' => ['inquiry_id' => $check->id, 'admin' => 2500]]);
                }
            }
            app(StoreCatalog::class)->reprice($store);
        }
    }
}
