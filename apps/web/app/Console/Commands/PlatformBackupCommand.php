<?php

namespace App\Console\Commands;

use App\Services\PlatformBackup;
use Illuminate\Console\Command;

class PlatformBackupCommand extends Command
{
    protected $signature = 'digitaltoko:backup';

    protected $description = 'Buat backup MySQL, media dan sesi WhatsApp terenkripsi.';

    public function handle(PlatformBackup $backup): int
    {
        try {
            $file = $backup->create();
            $this->info('Backup terenkripsi dan terverifikasi: '.$file);

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $backup->failure();
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
