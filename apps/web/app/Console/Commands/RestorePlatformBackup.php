<?php

namespace App\Console\Commands;

use App\Services\PlatformBackup;
use Illuminate\Console\Command;

class RestorePlatformBackup extends Command
{
    protected $signature = 'digitaltoko:backup-restore {file} {--database= : Database baru dt_restore_*} {--directory= : Direktori baru untuk pemulihan offline}';

    protected $description = 'Pulihkan backup ke database baru dan direktori offline, tanpa menimpa aplikasi aktif.';

    public function handle(PlatformBackup $backup): int
    {
        try {
            if (! $this->option('database') || ! $this->option('directory')) {
                throw new \RuntimeException('Isi --database=dt_restore_* dan --directory=lokasi_baru.');
            } $backup->restore($this->argument('file'), $this->option('database'), $this->option('directory'));
            $this->info('Pemulihan selesai pada database/direktori terpisah. Tidak ada layanan yang dijalankan.');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
