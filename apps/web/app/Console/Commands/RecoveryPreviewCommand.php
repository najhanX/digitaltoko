<?php

namespace App\Console\Commands;

use App\Services\RecoveryPreview;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RecoveryPreviewCommand extends Command
{
    protected $signature = 'digitaltoko:recovery-preview {id} {--port=8011}';

    protected $description = 'Buka hasil restore terpisah pada server loopback hanya-baca tanpa worker atau koneksi supplier.';

    public function handle(RecoveryPreview $preview): int
    {
        $raw = $this->option('port');
        if (! is_string($raw) || ! ctype_digit($raw)) {
            $this->error('Port tidak valid.');

            return self::FAILURE;
        }
        try {
            $router = $preview->prepare($this->argument('id'), (int) $raw);
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->info('Pratinjau: http://localhost:'.$raw.'/admin/login');
        $this->line('Login memakai akun pada snapshot. Hanya baca; API, pengubahan data, SMTP, supplier dan antrean dinonaktifkan. Ctrl+C untuk berhenti.');
        $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$raw, '-t', public_path(), $router], base_path());
        $server->setTimeout(null);
        $server->run(fn ($type, $text) => $this->output->write($text));

        return $server->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }
}
