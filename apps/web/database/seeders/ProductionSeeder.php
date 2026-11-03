<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call([AdminSeeder::class, PackagePlanSeeder::class]);
            DB::table('commerce_options')->insertOrIgnore(['id' => 'custom_qris_price', 'value' => 0]);
        }, 3);
        $this->command?->info('Data dasar production siap. Tinjau/aktifkan harga paket dan modul pembayaran dari panel admin. Tidak ada data dummy yang ditambahkan.');
    }
}
