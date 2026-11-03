<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Symfony\Component\Process\Process;

class RecoveryProcesses
{
    public const NAMES = ['digitaltoko-queue', 'digitaltoko-scheduler', 'digitaltoko-bot-manager'];

    private function command(array $arguments): string
    {
        $prefix = config('backup.pm2_cli') ? [config('backup.node'), config('backup.pm2_cli')] : ['pm2'];
        $process = new Process([...$prefix, ...$arguments], base_path(), ['NO_COLOR' => '1']);
        $process->setTimeout(300);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('PM2 belum dapat dikendalikan. Periksa BACKUP_PM2_CLI, PATH dan akun PM2 worker.');
        }

        return $process->getOutput();
    }

    public function inspect(): array
    {
        $data = json_decode($this->command(['jlist']), true, 512, JSON_THROW_ON_ERROR);
        $states = [];
        foreach (self::NAMES as $name) {
            $app = collect($data)->firstWhere('name', $name);
            if (! $app || ! in_array($app['pm2_env']['status'] ?? '', ['online', 'stopped'], true)) {
                throw new RuntimeException('Restore aktif membutuhkan proses PM2 '.$name.' terdaftar dan stabil. Hentikan worker manual lain sebelum melanjutkan.');
            }
            $states[$name] = $app['pm2_env']['status'];
        }

        return $states;
    }

    public function pause(array $states): void
    {
        foreach (self::NAMES as $name) {
            if (($states[$name] ?? null) === 'online') {
                $this->command(['stop', $name]);
            }
        }
        $after = $this->inspect();
        foreach ($after as $state) {
            if ($state !== 'stopped') {
                throw new RuntimeException('Proses penulisan belum berhenti. Restore dibatalkan.');
            }
        }
    }

    public function resume(array $states): void
    {
        foreach (self::NAMES as $name) {
            if (($states[$name] ?? null) === 'online') {
                $this->command(['restart', $name, '--update-env']);
            }
        }
    }

    public function down(): void
    {
        if (app()->isDownForMaintenance()) {
            throw new RuntimeException('Aplikasi sudah maintenance. Selesaikan operasi sebelumnya terlebih dahulu.');
        }
        Artisan::call('down', ['--retry' => 30]);
    }

    public function up(): void
    {
        Artisan::call('up');
    }
}
