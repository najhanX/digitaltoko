<?php

namespace App\Console\Commands;

use App\Services\PlatformBackup;
use Illuminate\Console\Command;

class DrillPlatformBackup extends Command
{
    protected $signature = 'digitaltoko:backup-drill {file}';

    protected $description = 'Uji pemulihan pada database/folder sementara, periksa data terenkripsi, lalu bersihkan salinannya.';

    public function handle(PlatformBackup $backup): int
    {
        try {
            $result = $backup->drill($this->argument('file'));
            $this->info('Uji pemulihan offline berhasil. Salinan sementara dibersihkan; aplikasi aktif tidak diubah.');
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
