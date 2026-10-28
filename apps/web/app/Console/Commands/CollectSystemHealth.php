<?php

namespace App\Console\Commands;

use App\Services\SystemHealth;
use Illuminate\Console\Command;

class CollectSystemHealth extends Command
{
    protected $signature = 'digitaltoko:health-check';

    protected $description = 'Periksa layanan tanpa mengirim ulang transaksi atau mengubah heartbeat scheduler.';

    public function handle(SystemHealth $health): int
    {
        if (! $health->collect()) {
            $this->warn('Pemeriksaan lain masih berjalan.');

            return self::SUCCESS;
        }
        $this->info('Pemeriksaan kesehatan selesai. Lihat Admin → Kesehatan sistem.');

        return self::SUCCESS;
    }
}
