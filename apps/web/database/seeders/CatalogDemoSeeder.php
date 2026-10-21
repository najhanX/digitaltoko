<?php

namespace Database\Seeders;

use App\Models\CatalogProduct;
use App\Models\PremiumStock;
use App\Models\PricingRule;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreCatalog;
use App\Support\CatalogAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CatalogDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Katalog demo hanya untuk local/testing.');
        }
        if (! User::where('email', 'demo-owner@example.test')->exists()) {
            $this->call(DemoStoreSeeder::class);
        }
        $owner = User::where('email', 'demo-owner@example.test')->firstOrFail();
        foreach (['demo-aurora', 'demo-nebula', 'demo-atlas', 'demo-bot'] as $slug) {
            DB::transaction(function () use ($owner, $slug) {
                $store = Store::where('slug', $slug)->lockForUpdate()->firstOrFail();
                if ($store->owner_id !== $owner->id) {
                    throw new \RuntimeException('Toko demo dimiliki akun lain.');
                }
                PricingRule::firstOrCreate(['store_id' => $store->id, 'scope' => 'service', 'key' => 'aplikasi premium'], ['markup' => '5% + 1000', 'auto_update' => true]);
                for ($i = 1; $i <= 18; $i++) {
                    $premium = $i % 3 === 0;
                    $product = CatalogProduct::firstOrCreate(['store_id' => $store->id, 'code' => 'DEMO-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)],
                        ['name' => ($premium ? 'Aplikasi Premium' : 'Pulsa / Game').' Demo '.$i, 'description' => 'Produk simulasi untuk pengujian katalog. Bukan produk yang dapat dibeli.',
                            'kind' => $premium ? 'premium' : 'ppob', 'service' => $premium ? 'Aplikasi premium' : 'Prabayar', 'category' => $premium ? 'Premium demo' : 'Digital demo', 'sale_price' => 10000 + $i * 1000, 'active' => true]);
                    if ($premium && CatalogAccess::supports($store, 'stock')) {
                        ProductMapping::firstOrCreate(['product_id' => $product->id, 'source_key' => 'stock'], ['store_id' => $store->id, 'source' => 'stock']);
                        for ($stock = 1; $stock <= 2; $stock++) {
                            PremiumStock::firstOrCreate(['store_id' => $store->id, 'product_id' => $product->id, 'note' => 'DEMO-STOK-'.$stock],
                                ['payload' => 'AKUN SIMULASI — BUKAN CREDENTIAL ASLI. Produk '.$i.' / stok '.$stock, 'cost' => 5000 + $i * 100]);
                        }
                    }
                }
            });
            app(StoreCatalog::class)->reprice(Store::where('slug', $slug)->firstOrFail());
        }
        $this->command?->info('Katalog demo disiapkan tanpa koneksi pemasok atau transaksi nyata.');
    }
}
