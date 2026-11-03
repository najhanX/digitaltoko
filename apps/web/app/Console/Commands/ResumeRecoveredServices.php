<?php

namespace App\Console\Commands;

use App\Services\LiveBackupRestore;
use App\Services\RecoveryProcesses;
use Illuminate\Console\Command;

class ResumeRecoveredServices extends Command
{
    protected $signature = 'digitaltoko:recovery-resume {id} {--reviewed : Konfirmasi transaksi tertunda dan callback lama sudah diperiksa}';

    protected $description = 'Buka kembali penulisan dan layanan PM2 setelah hasil restore aktif ditinjau.';

    public function handle(LiveBackupRestore $restore, RecoveryProcesses $processes): int
    {
        if (! $this->option('reviewed')) {
            $this->error('Tinjau pesanan, pembayaran dan callback tertunda terlebih dahulu, lalu sertakan --reviewed.');

            return self::FAILURE;
        }
        try {
            $restore->resume($this->argument('id'), $processes);
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->info('Mode tinjauan selesai. Hanya layanan yang sebelumnya online dijalankan kembali.');

        return self::SUCCESS;
    }
}
