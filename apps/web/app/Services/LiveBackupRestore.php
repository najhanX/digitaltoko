<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class LiveBackupRestore
{
    public function journal(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('ID pemulihan tidak valid.');
        }

        return app(BackupRecovery::class)->root().'/.live-restore-'.$id.'.json';
    }

    public function read(string $id): array
    {
        $path = $this->journal($id);
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('Catatan pemulihan tidak tersedia.');
        }

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function record(string $id, array $data): void
    {
        $path = $this->journal($id);
        $previous = is_file($path) ? $this->read($id) : [];
        $temporary = $path.'.tmp';
        file_put_contents($temporary, json_encode([...$previous, ...$data], JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($temporary, 0600);
        if (! $this->move($temporary, $path)) {
            throw new RuntimeException('Jurnal pemulihan tidak dapat disimpan.');
        }
    }

    public function run(array $data, PlatformBackup $backup, RecoveryProcesses $processes): void
    {
        $id = $data['id'];
        $requests = app(BackupRecovery::class);
        $archive = $requests->input($id);
        $this->record($id, ['state' => 'processing', 'phase' => 'Memverifikasi dan menyiapkan snapshot']);
        if (! config('backup.live_enabled') || is_file(config('backup.restore_hold'))) {
            throw new RuntimeException('Restore aktif belum diizinkan atau hasil pemulihan sebelumnya belum ditinjau.');
        }
        $manifest = $backup->verify($archive);
        if (! hash_equals(config('app.key'), $manifest['app_key'])) {
            throw new RuntimeException('APP_KEY backup berbeda. Gunakan pemulihan terpisah dan prosedur operator untuk pergantian kunci.');
        }
        $states = $processes->inspect();
        $destination = $requests->destination($id);
        $backup->restore($archive, $data['database'], $destination);
        $this->record($id, ['state' => 'processing', 'phase' => 'Persiapan hasil backup selesai', 'database' => DB::connection()->getDatabaseName(), 'processes' => $states]);
        $down = false;
        $changed = false;
        $lease = fopen(config('backup.restore_lock'), 'c');
        try {
            $processes->down();
            $down = true;
            $processes->pause($states);
            if (! flock($lease, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Masih ada permintaan web berjalan. Tunggu hingga selesai, lalu ajukan restore kembali.');
            }
            if (DB::table('customer_orders')->whereNotIn('status', ['success', 'failed', 'cancelled', 'expired'])->exists()
                || DB::table('subscription_invoices')->whereIn('status', ['pending', 'paid', 'review'])->exists()
                || DB::table('module_purchases')->whereIn('status', ['pending', 'review'])->exists()) {
                throw new RuntimeException('Masih ada transaksi/pembayaran aktif. Selesaikan terlebih dahulu sebelum menimpa data.');
            }
            foreach (config('backup.sources') as $alias => $target) {
                if (! in_array($alias, ['private', 'public', 'whatsapp'], true) || ! realpath(dirname($target)) || is_link($target) || (file_exists($target) && ! is_dir($target))) {
                    throw new RuntimeException('Konfigurasi media aktif tidak aman untuk restore.');
                }
                if (is_dir($target)) {
                    $scan = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS));
                    foreach ($scan as $entry) {
                        if ($entry->isLink()) {
                            throw new RuntimeException('Symlink pada media aktif tidak boleh ditimpa.');
                        }
                    }
                    unset($scan, $entry);
                }
            }
            $before = $backup->create();
            $hold = ['id' => $id, 'database' => DB::connection()->getDatabaseName(), 'before_backup' => basename($before), 'created_at' => now()->toIso8601String()];
            file_put_contents(config('backup.restore_hold'), json_encode($hold, JSON_THROW_ON_ERROR), LOCK_EX);
            chmod(config('backup.restore_hold'), 0600);
            $this->record($id, ['before_backup' => basename($before), 'phase' => 'Mengganti database aktif']);
            $changed = true;
            $backup->replaceActiveDatabase($destination);
            foreach (config('backup.sources') as $alias => $target) {
                if (! in_array($alias, ['private', 'public', 'whatsapp'], true)) {
                    throw new RuntimeException('Alias media tidak didukung restore aktif.');
                }
                $parent = realpath(dirname($target));
                if (! $parent || is_link($target) || (file_exists($target) && ! is_dir($target))) {
                    throw new RuntimeException('Direktori media aktif tidak valid.');
                }
                $target = $parent.DIRECTORY_SEPARATOR.basename($target);
                $suffix = substr(str_replace('-', '', $id), 0, 12);
                $prepared = $parent.DIRECTORY_SEPARATOR.'.r-'.$alias.'-'.$suffix;
                $old = $parent.DIRECTORY_SEPARATOR.'.old-'.$alias.'-'.$suffix;
                if (file_exists($prepared) || file_exists($old)) {
                    throw new RuntimeException('Folder penukaran sudah ada. Periksa hasil sebelumnya.');
                }
                File::ensureDirectoryExists($prepared, 0700);
                $source = $destination.'/files/'.$alias;
                if (is_dir($source) && ! File::copyDirectory($source, $prepared)) {
                    throw new RuntimeException('Media pemulihan tidak dapat disalin.');
                }
                $this->swapFiles($target, $prepared, $old);
                $this->record($id, ['phase' => 'Media '.$alias.' dipulihkan']);
            }
            $this->quarantine();
            DB::table('system_health_signals')->updateOrInsert(['name' => 'restore_'.$id], ['group' => 'backup_restores', 'label' => 'Restore aktif', 'status' => 'completed', 'details' => json_encode([...$data, 'mode' => 'active', 'active_database' => DB::connection()->getDatabaseName(), 'before_backup' => basename($before)]), 'checked_at' => now()]);
            $this->record($id, ['state' => 'completed', 'phase' => 'Data aktif dipulihkan. Tinjau transaksi lama sebelum menjalankan layanan background.', 'finished_at' => now()->toIso8601String()]);
            $processes->up();
            $down = false;
        } catch (\Throwable $error) {
            $this->record($id, ['state' => 'failed', 'phase' => $changed ? 'Pemulihan terhenti setelah perubahan. Maintenance dipertahankan; gunakan backup sebelum restore dan log operator.' : 'Persiapan gagal. Data aktif tidak ditimpa.', 'maintenance' => $changed]);
            if (! $changed && $down) {
                $processes->resume($states);
                $processes->up();
            }
            throw $error;
        } finally {
            if (is_resource($lease)) {
                flock($lease, LOCK_UN);
                fclose($lease);
            }
        }
    }

    private function swapFiles(string $target, string $prepared, string $old): void
    {
        File::ensureDirectoryExists($target, 0700);
        File::ensureDirectoryExists($old, 0700);
        foreach ([$target => $old, $prepared => $target] as $source => $destination) {
            $source = realpath($source);
            $files = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException('Symlink pada media aktif tidak boleh ditimpa.');
                }
                if ($file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
            unset($iterator, $file);
            foreach ($files as $file) {
                $relative = substr($file, strlen($source) + 1);
                $to = $destination.DIRECTORY_SEPARATOR.$relative;
                File::ensureDirectoryExists(dirname($to), 0700);
                if (! $this->move($file, $to)) {
                    throw new RuntimeException('Berkas media masih terkunci atau tidak dapat dipindahkan. Maintenance dipertahankan.');
                }
            }
        }
    }

    private function move(string $source, string $target): bool
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            gc_collect_cycles();
            clearstatcache();
            if (@rename($source, $target)) {
                return true;
            }
            usleep(50000);
        }

        return false;
    }

    private function quarantine(): void
    {
        DB::table('jobs')->delete();
        DB::table('sessions')->delete();
        DB::table('customer_notices')->whereNull('wa_sent_at')->update(['wa_sent_at' => now()]);
        DB::table('customer_notices')->whereNull('email_sent_at')->update(['email_sent_at' => now()]);
        foreach (['users', 'customers', 'store_staff'] as $table) {
            DB::table($table)->update(['remember_token' => null]);
        }
        if (config('session.driver') === 'file') {
            $path = config('session.files');
            if (is_dir($path) && ! is_link($path) && realpath(dirname($path)) === realpath(storage_path('framework'))) {
                rename($path, $path.'.before-restore-'.Str::uuid());
                File::ensureDirectoryExists($path, 0700);
            }
        }
    }

    public function resume(string $id, RecoveryProcesses $processes): void
    {
        $journal = $this->read($id);
        if (! is_file(config('backup.restore_hold'))) {
            throw new RuntimeException('Tidak ada pemulihan aktif yang menunggu tinjauan.');
        }
        $hold = json_decode(file_get_contents(config('backup.restore_hold')), true);
        if (($hold['id'] ?? null) !== $id || $journal['state'] !== 'completed' || ($hold['database'] ?? null) !== DB::connection()->getDatabaseName()) {
            throw new RuntimeException('Hasil pemulihan belum lengkap atau database tidak sesuai.');
        }
        $processes->resume($journal['processes']);
        unlink(config('backup.restore_hold'));
        $this->record($id, ['resumed_at' => now()->toIso8601String()]);
    }
}
