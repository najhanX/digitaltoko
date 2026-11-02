<?php

namespace Database\Seeders;

use App\Models\PlanOption;
use App\Support\PackageCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PackagePlanSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            PlanOption::where(fn ($query) => $query->where('category', '!=', 'full')->orWhere('plan', '!=', 'galaxy'))->update(['active' => false]);
            foreach (PackageCatalog::all() as $package) {
                $offers = PlanOption::where('category', $package['category'])->where('plan', $package['plan'])->lockForUpdate()->get();
                if ($offers->isEmpty()) {
                    PlanOption::create(['name' => PackageCatalog::offerName($package['category'], $package['plan'], 30),
                        'category' => $package['category'], 'plan' => $package['plan'], 'price' => 50000, 'duration_days' => 30, 'active' => false]);
                } else {
                    foreach ($offers as $offer) {
                        if (preg_match('/\b(Orbit|Space|Galaxy|Jupiter|Saturn|Andromeda)\b/i', $offer->name)) {
                            $offer->update(['name' => PackageCatalog::offerName($offer->category, $offer->plan, $offer->duration_days)]);
                        }
                    }
                }
            }
        });
        $this->command?->info('Satu paket Web Topup + Bot WhatsApp disiapkan. Harga dan durasi paket lengkap dipertahankan; penawaran terpisah dinonaktifkan.');
    }
}
