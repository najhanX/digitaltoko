<?php

namespace App\Services;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class PlatformBackup
{
    public function configured(): bool
    {
        try {
            $this->key();

            return (bool) config('backup.enabled') && DB::connection()->getDriverName() === 'mysql'
                && config('queue.connections.backups.driver') === 'database'
                && (int) config('queue.connections.backups.retry_after') > 3600;
        } catch (\Throwable) {
            return false;
        }
    }

    private function key(): string
    {
        $value = (string) config('backup.key');
        if (! str_starts_with($value, 'base64:') || strlen(base64_decode(substr($value, 7), true) ?: '') !== 32) {
            throw new RuntimeException('Atur BACKUP_KEY base64 32 byte; simpan salinan kunci terpisah.');
        }
        if (! ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256)) {
            throw new RuntimeException('PHP ZIP memerlukan dukungan AES-256.');
        }

        return substr($value, 7);
    }

    private function directory(string $path): string
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true)) {
            throw new RuntimeException('Direktori backup tidak dapat dibuat.');
        }
        $path = realpath($path);
        if (! $path || is_link($path)) {
            throw new RuntimeException('Direktori tidak valid.');
        }

        return $path;
    }

    /** Reject links; only immutable copies spanning the database snapshot may be published. */
    private function inventory(): array
    {
        $files = [];
        foreach (config('backup.sources') as $alias => $root) {
            if (! preg_match('/^[a-z_]+$/', $alias)) {
                throw new RuntimeException('Alias backup tidak valid.');
            }
            if (! is_dir($root)) {
                continue;
            }
            if (is_link($root)) {
                throw new RuntimeException('Sumber backup tidak boleh symlink.');
            }
            $root = realpath($root);
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if (preg_match('#(^|/)(backups|recovery)(/|$)#', $relative) || str_ends_with($relative, '.lock') || str_ends_with($relative, '.tmp')) {
                    continue;
                }
                if ($file->isLink()) {
                    throw new RuntimeException('Sumber backup mengandung symlink.');
                }
                if (! $file->isFile()) {
                    continue;
                }
                $files['files/'.$alias.'/'.$relative] = ['path' => $file->getPathname(), 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getPathname())];
            }
        }
        ksort($files);

        return $files;
    }

    private function mysqlConfig(string $directory): string
    {
        $connection = DB::connection()->getConfig();
        if (($connection['driver'] ?? '') !== 'mysql') {
            throw new RuntimeException('Backup ini memerlukan MySQL; koneksi lain tidak diekspor diam-diam.');
        }
        $escape = fn ($s) => '"'.str_replace(['\\', "\n", "\r", '"'], ['\\\\', '\\n', '\\r', '\\"'], (string) $s).'"';
        $body = "[client]\n";
        foreach (['user' => 'username', 'password' => 'password', 'host' => 'host', 'port' => 'port', 'socket' => 'unix_socket'] as $option => $field) {
            if ($field === 'password' || filled($connection[$field] ?? null)) {
                $body .= $option.'='.$escape($connection[$field])."\n";
            }
        }
        $path = $directory.'/client.cnf';
        file_put_contents($path, $body);
        chmod($path, 0600);

        return $path;
    }

    private function process(array $arguments, mixed $input = null): void
    {
        $process = new Process($arguments, base_path());
        $process->setTimeout(3600)->setInput($input)->disableOutput()->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Proses MySQL gagal. Periksa binary, hak akses dan koneksi; kredensial tidak ditampilkan.');
        }
    }

    public function create(): string
    {
        $key = $this->key();
        $root = $this->directory(config('backup.directory'));
        foreach (array_filter([$root, config('backup.replica')]) as $output) {
            $output = $this->directory($output);
            $web = realpath(public_path());
            if ($web && ($output === $web || str_starts_with($output, $web.DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Backup tidak boleh berada di web root.');
            }
            foreach (config('backup.sources') as $sourceRoot) {
                $sourceRoot = realpath($sourceRoot);
                if ($sourceRoot && ($output === $sourceRoot || str_starts_with($output, $sourceRoot.DIRECTORY_SEPARATOR))) {
                    throw new RuntimeException('Backup/replika harus berada di luar sumber media/sesi.');
                }
            }
        }
        foreach (config('backup.sources') as $source) {
            $source = realpath($source);
            if ($source && ($root === $source || str_starts_with($root, $source.DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Direktori backup harus di luar sumber media/sesi.');
            }
        }
        $lock = fopen($root.'/.backup.lock', 'c+');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Backup lain sedang berjalan.');
        }
        foreach (glob($root.'/.work-*', GLOB_ONLYDIR) as $stale) {
            $resolved = realpath($stale);
            if (! is_link($stale) && $resolved && dirname($resolved) === $root && preg_match('/^\.work-[a-f0-9-]{36}$/D', basename($stale))) {
                File::deleteDirectory($resolved);
            }
        }
        $work = $this->directory($root.'/.work-'.Str::uuid());
        $name = 'digitaltoko-'.now()->format('Ymd-Hisu').'-'.Str::lower(Str::random(8)).'.zip';
        $partial = $root.'/'.$name.'.partial';
        $zip = null;
        try {
            $before = $this->inventory();
            $zip = new ZipArchive;
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Arsip tidak dapat dibuat.');
            }
            $zip->setPassword($key);
            $entries = [];
            foreach ($before as $entry => $file) {
                $copy = $work.'/'.hash('sha256', $entry);
                if (! copy($file['path'], $copy)) {
                    throw new RuntimeException('Media tidak dapat disalin.');
                } chmod($copy, 0600);
                $hash = hash_file('sha256', $copy);
                if (! hash_equals($file['sha256'], $hash)) {
                    throw new RuntimeException('Media berubah saat backup. Ulangi pada waktu sepi.');
                }
                $zip->addFile($copy, $entry);
                $entries[$entry] = ['size' => filesize($copy), 'sha256' => $hash];
            }
            $database = (string) DB::connection()->getDatabaseName();
            if (! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
                throw new RuntimeException('Nama database tidak didukung.');
            }
            $engines = DB::select('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? AND ENGINE <> ?', [$database, 'BASE TABLE', 'InnoDB']);
            if ($engines) {
                throw new RuntimeException('Snapshot online memerlukan seluruh tabel InnoDB.');
            }
            $cnf = $this->mysqlConfig($work);
            $sql = $work.'/database.sql';
            touch($sql);
            chmod($sql, 0600);
            $args = [config('backup.mysqldump'), '--defaults-extra-file='.$cnf, '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--hex-blob', '--default-character-set=utf8mb4', '--skip-comments', '--result-file='.$sql];
            $version = DB::selectOne('SELECT VERSION() AS version')->version;
            if (! str_contains($version, 'MariaDB')) {
                $args[] = '--set-gtid-purged=OFF';
            }
            $args[] = $database;
            $this->process($args);
            if (! filesize($sql)) {
                throw new RuntimeException('Dump database kosong.');
            }
            $zip->addFile($sql, 'database.sql');
            $entries['database.sql'] = ['size' => filesize($sql), 'sha256' => hash_file('sha256', $sql)];
            if ($before !== $this->inventory()) {
                throw new RuntimeException('Media/sesi berubah selama snapshot. Tidak ada backup baru yang dipublikasikan; coba ulang saat tenang atau hentikan penulis untuk maintenance.');
            }
            $manifest = ['version' => 1, 'created_at' => now()->toIso8601String(), 'database' => $database, 'app_key' => config('app.key'), 'files' => $entries, 'sources' => array_keys(config('backup.sources')), 'consistency' => 'InnoDB snapshot; media hashes unchanged across snapshot; no concurrent schema migration'];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            foreach ([...array_keys($entries), 'manifest.json'] as $entry) {
                if (! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
                    throw new RuntimeException('Enkripsi arsip gagal.');
                }
            }
            if (! $zip->close()) {
                throw new RuntimeException('Penulisan arsip gagal.');
            } $zip = null;
            $this->verify($partial);
            $final = $root.'/'.$name;
            if (! rename($partial, $final)) {
                throw new RuntimeException('Publikasi backup gagal.');
            }
            $replica = config('backup.replica');
            if ($replica) {
                $replica = $this->directory($replica);
                if ($replica === $root) {
                    throw new RuntimeException('Lokasi replika harus berbeda dari backup utama.');
                }
                if (! copy($final, $replica.'/'.$name.'.partial') || ! hash_equals(hash_file('sha256', $final), hash_file('sha256', $replica.'/'.$name.'.partial'))) {
                    throw new RuntimeException('Salinan replika gagal; backup lokal dipertahankan.');
                }
                chmod($replica.'/'.$name.'.partial', 0600);
                if (! rename($replica.'/'.$name.'.partial', $replica.'/'.$name)) {
                    throw new RuntimeException('Publikasi replika gagal.');
                }
            }
            $this->state(['status' => 'ok', 'last_success_at' => now()->toIso8601String(), 'file' => $name, 'sha256' => hash_file('sha256', $final), 'replicated' => (bool) $replica]);
            $this->prune($root);
            if ($replica) {
                $this->prune($replica);
            }

            return $final;
        } catch (\Throwable $error) {
            $this->state(['status' => 'error', 'message' => 'Backup terakhir gagal. Jalankan digitaltoko:backup untuk diagnosis; backup sebelumnya dipertahankan.']);
            throw $error;
        } finally {
            if ($zip instanceof ZipArchive) {
                $zip->close();
            }
            if (is_file($partial)) {
                unlink($partial);
            }
            File::deleteDirectory($work);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function state(array $data): void
    {
        $path = config('backup.directory').'/status.json';
        $old = is_file($path) ? json_decode(file_get_contents($path), true) : [];
        $old = is_array($old) ? $old : [];
        file_put_contents($path.'.tmp', json_encode([...($old ?? []), ...$data, 'checked_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR));
        chmod($path.'.tmp', 0600);
        rename($path.'.tmp', $path);
    }

    public function failure(): void
    {
        try {
            $this->directory(config('backup.directory'));
            $this->state(['status' => 'error', 'message' => 'Backup gagal; periksa log perintah digitaltoko:backup. Backup sebelumnya dipertahankan.']);
        } catch (\Throwable) {
        }
    }

    public function health(): array
    {
        $path = config('backup.directory').'/status.json';
        $raw = is_readable($path) ? @file_get_contents($path) : false;
        $state = $raw !== false ? json_decode($raw, true) : [];
        $state = is_array($state) ? $state : [];
        $at = $state['last_success_at'] ?? null;
        try {
            $date = is_string($at) ? Carbon::parse($at) : null;
            $fresh = $date && $date->gt(now()->subHours(config('backup.max_age_hours'))) && $date->lte(now()->addMinutes(5));
            $at = $date?->toIso8601String();
        } catch (\Throwable) {
            $fresh = false;
            $at = null;
        }
        $exists = is_string($state['file'] ?? null) && basename($state['file']) === $state['file'] && is_file(config('backup.directory').'/'.$state['file']);
        $status = ($state['status'] ?? null) === 'error' ? 'error' : ($fresh && $exists ? 'ok' : 'warning');

        return ['id' => 'backup', 'label' => 'Backup terenkripsi', 'status' => $status, 'message' => $status === 'ok' ? ('Backup terakhir terverifikasi.'.(! empty($state['replicated']) ? ' Salinan replika tersimpan.' : ' Belum ada salinan di lokasi kedua.')) : 'Backup gagal, belum tersedia atau melewati batas umur. Jalankan digitaltoko:backup dan periksa penyimpanan/kunci.', 'checked_at' => $at, 'workers' => null];
    }

    private function archive(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if (is_link($path) || $zip->open($path) !== true) {
            throw new RuntimeException('Arsip tidak dapat dibuka.');
        }
        $zip->setPassword($this->key());

        return $zip;
    }

    private function manifest(ZipArchive $zip): array
    {
        $meta = $zip->statName('manifest.json');
        if (! $meta || ($meta['encryption_method'] ?? 0) !== ZipArchive::EM_AES_256 || $meta['size'] > 16777216) {
            throw new RuntimeException('Manifest tidak valid.');
        }
        $raw = @$zip->getFromName('manifest.json');
        $data = $raw === false ? null : json_decode($raw, true);
        if (! is_array($data) || ($data['version'] ?? null) !== 1 || ! is_array($data['files'] ?? null) || ! isset($data['files']['database.sql']) || ! is_string($data['app_key'] ?? null)) {
            throw new RuntimeException('Kunci salah, arsip rusak atau manifest tidak valid.');
        }
        if ($zip->numFiles !== count($data['files']) + 1) {
            throw new RuntimeException('Isi arsip tidak sesuai manifest.');
        }

        return $data;
    }

    public function verify(string $path): array
    {
        $zip = $this->archive($path);
        try {
            $manifest = $this->manifest($zip);
            $total = 0;
            foreach ($manifest['files'] as $name => $expected) {
                if (! is_string($name) || ! preg_match('#^(database\.sql|files/[a-z_]+/[^\\\\]+)$#D', $name) || preg_match('#(^|/)\.\.?(/|$)#', $name) || str_contains($name, ':')) {
                    throw new RuntimeException('Path arsip tidak aman.');
                }
                $stat = $zip->statName($name);
                if (! $stat || ($stat['encryption_method'] ?? 0) !== ZipArchive::EM_AES_256 || ! is_int($expected['size'] ?? null) || $expected['size'] < 0 || $stat['size'] !== $expected['size'] || ($total += $stat['size']) > config('backup.restore_limit_bytes')) {
                    throw new RuntimeException('Ukuran arsip tidak valid atau melewati batas pemulihan.');
                }
                $stream = @$zip->getStream($name);
                if (! $stream) {
                    throw new RuntimeException('Arsip tidak dapat didekripsi.');
                }
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                fclose($stream);
                if (! hash_equals($expected['sha256'], hash_final($hash))) {
                    throw new RuntimeException('Checksum backup tidak cocok.');
                }
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    private function prune(string $directory): void
    {
        $files = array_filter(glob($directory.'/digitaltoko-*.zip'), fn ($p) => preg_match('/^digitaltoko-\d{8}-\d{6}(?:\d{6})?-[a-z0-9]{8}\.zip$/', basename($p)) && ! is_link($p));
        rsort($files);
        foreach (array_slice($files, max(1, (int) config('backup.keep'))) as $file) {
            $this->verify($file);
            unlink($file);
        }
    }

    /** Exercise the real import and encrypted data without activating any restored services. */
    public function drill(string $archive): array
    {
        $suffix = str_replace('-', '', (string) Str::uuid());
        $database = 'dt_restore_'.$suffix;
        $root = $this->directory(config('backup.directory'));
        $directory = $root.'/.drill-'.$suffix;
        $manifest = $this->verify($archive);
        $result = [];
        $owned = false;
        try {
            $this->restore($archive, $database, $directory, function () use (&$owned): void {
                $owned = true;
            });
            config(['database.connections.backup_drill' => [...DB::connection()->getConfig(), 'database' => $database, 'url' => null]]);
            DB::purge('backup_drill');
            foreach (['migrations', 'stores', 'customers', 'customer_orders', 'customer_ledgers', 'premium_stocks', 'discount_vouchers', 'voucher_redemptions'] as $table) {
                $result[$table] = DB::connection('backup_drill')->table($table)->count();
            }
            $sample = DB::connection('backup_drill')->table('premium_stocks')->whereNotNull('payload')->value('payload');
            if ($sample !== null) {
                $key = $manifest['app_key'];
                $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
                (new Encrypter($key, config('app.cipher')))->decrypt($sample, false);
            }
            $this->state(['last_restore_verified_at' => now()->toIso8601String(), 'last_restore_sha256' => hash_file('sha256', $archive)]);

            return ['tables' => $result, 'encrypted_stock_checked' => $sample !== null, 'offline' => true];
        } finally {
            DB::purge('backup_drill');
            if ($owned && preg_match('/^dt_restore_[a-f0-9]{32}$/D', $database) && $database !== DB::connection()->getDatabaseName()) {
                DB::statement('DROP DATABASE IF EXISTS `'.$database.'`');
            }
            $absolute = realpath($directory);
            if ($absolute && ! is_link($directory) && dirname($absolute) === $root && basename($absolute) === '.drill-'.$suffix) {
                File::deleteDirectory($absolute);
            }
        }
    }

    /** Restore only into a new isolated schema and a new offline directory; never start services. */
    public function restore(string $archive, string $database, string $destination, ?\Closure $onCreated = null): void
    {
        if (! preg_match('/^dt_restore_[a-z0-9_]{1,45}$/D', $database) || $database === DB::connection()->getDatabaseName() || file_exists($destination)) {
            throw new RuntimeException('Gunakan database baru berawalan dt_restore_ dan direktori pemulihan yang belum ada.');
        }
        $manifest = $this->verify($archive);
        if (DB::select('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$database])) {
            throw new RuntimeException('Database tujuan sudah ada; tidak ditimpa.');
        }
        $destination = $this->directory($destination);
        foreach (array_filter([realpath(public_path()), ...array_map(fn ($path) => realpath($path), config('backup.sources'))]) as $protected) {
            if ($destination === $protected || str_starts_with($destination, $protected.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Pemulihan harus di luar web root dan sumber media/sesi aktif.');
            }
        }

        $zip = $this->archive($archive);
        $cnf = null;
        $input = null;
        try {
            foreach ($manifest['files'] as $name => $expected) {
                $target = $destination.'/'.$name;
                $this->directory(dirname($target));
                $input = $zip->getStream($name);
                $output = fopen($target, 'xb');
                chmod($target, 0600);
                stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                if (filesize($target) !== $expected['size'] || ! hash_equals($expected['sha256'], hash_file('sha256', $target))) {
                    throw new RuntimeException('Berkas pemulihan tidak cocok dengan manifest.');
                }
            }
            file_put_contents($destination.'/.env.recovery', 'APP_KEY='.$manifest['app_key']."\nDB_DATABASE=".$database."\n");
            chmod($destination.'/.env.recovery', 0600);
            DB::statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');
            if ($onCreated) {
                $onCreated();
            }
            $cnf = $this->mysqlConfig($destination);
            $input = fopen($destination.'/database.sql', 'rb');
            try {
                $this->process([config('backup.mysql'), '--defaults-extra-file='.$cnf, '--binary-mode=1', '--default-character-set=utf8mb4', '--database='.$database], $input);
            } finally {
                fclose($input);
                $input = null;
                unlink($cnf);
                $cnf = null;
            }
            file_put_contents($destination.'/RESTORED.json', json_encode(['database' => $database, 'restored_at' => now()->toIso8601String(), 'source_sha256' => hash_file('sha256', $archive), 'offline' => true]));
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if ($cnf && is_file($cnf)) {
                unlink($cnf);
            }
            $zip->close();
        }
    }
}
