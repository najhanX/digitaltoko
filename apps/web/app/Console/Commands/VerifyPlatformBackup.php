<?php

namespace App\Console\Commands;

use App\Services\PlatformBackup;
use Illuminate\Console\Command;

class VerifyPlatformBackup extends Command
{
    protected $signature = 'digitaltoko:backup-verify {file}';

    protected $description = 'Verifikasi kunci, path, ukuran dan checksum backup.';

    public function handle(PlatformBackup $backup): int
    {
        try {
            $backup->verify($this->argument('file'));
            $this->info('Backup berhasil diverifikasi; database tidak diubah.');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
