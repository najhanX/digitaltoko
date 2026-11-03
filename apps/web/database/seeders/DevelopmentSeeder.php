<?php

namespace Database\Seeders;

use App\Models\PlanOption;
use App\Support\PackageCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'development', 'testing')) {
            throw new RuntimeException('DevelopmentSeeder hanya untuk APP_ENV=local, development atau testing.');
        }
        DB::transaction(function (): void {
            $existing = PlanOption::pluck('id')->all();
            $this->call([ProductionSeeder::class, DemoStoreSeeder::class, CatalogDemoSeeder::class, DevelopmentActivitySeeder::class]);
            foreach ([7 => 15000, 30 => 50000, 90 => 135000, 180 => 250000, 365 => 450000] as $days => $price) {
                $offer = PlanOption::firstOrCreate(['category' => 'full', 'plan' => 'galaxy', 'duration_days' => $days],
                    ['name' => PackageCatalog::offerName('full', 'galaxy', $days), 'price' => $price, 'active' => true]);
                if (! in_array($offer->id, $existing, true) && ! $offer->active) {
                    $offer->update(['active' => true]);
                }
            }
        }, 3);
        $this->command?->info('Development siap: 8 toko demo, 240 produk, 100 pelanggan, 160 pesanan sandbox, 24 invoice sewa simulasi dan 5 durasi paket contoh pada database baru. Tidak menghubungi supplier/payment gateway.');
    }
}
