<?php

namespace App\Services;

use App\Jobs\RestorePlatformBackup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BackupRecovery
{
    public function root(): string
    {
        $path = config('backup.directory');
        if (is_link($path)) {
            throw new RuntimeException('Direktori backup tidak valid.');
        }
        File::ensureDirectoryExists($path, 0700);
        $root = realpath($path);
        foreach (array_filter([realpath(public_path()), ...array_map(fn ($source) => realpath($source), config('backup.sources'))]) as $protected) {
            if ($root === $protected || str_starts_with($root, $protected.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Direktori backup harus berada di luar penyimpanan aktif dan web root.');
            }
        }

        return $root;
    }

    public function snapshot(string $name): string
    {
        abort_unless(preg_match('/^digitaltoko-\d{8}-\d{6}(?:\d{6})?-[a-z0-9]{8}\.zip$/D', $name), 404);
        $root = $this->root();
        $path = $root.DIRECTORY_SEPARATOR.$name;
        abort_unless(is_file($path) && ! is_link($path) && dirname(realpath($path)) === $root, 404);

        return $path;
    }

    public function snapshots(): array
    {
        $items = [];
        foreach (glob($this->root().'/digitaltoko-*.zip') ?: [] as $path) {
            if (! preg_match('/^digitaltoko-\d{8}-\d{6}(?:\d{6})?-[a-z0-9]{8}\.zip$/D', basename($path)) || is_link($path) || ! is_file($path)) {
                continue;
            }
            $items[] = ['id' => basename($path), 'name' => basename($path), 'bytes' => filesize($path), 'created_at' => Carbon::createFromTimestampUTC(filemtime($path))->toIso8601String()];
        }
        usort($items, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $items;
    }

    public function operation(object $row): array
    {
        $data = json_decode($row->details, true);
        $data = is_array($data) ? $data : [];
        try {
            $expired = Carbon::parse($data['deadline'] ?? '1970-01-01')->lte(now());
        } catch (\Throwable) {
            $expired = true;
        }
        $state = in_array($row->status, ['queued', 'processing'], true) && $expired ? 'failed' : $row->status;
        $id = $data['id'] ?? substr($row->name, 8);

        return ['id' => $id, 'name' => $data['source'] ?? 'Arsip backup', 'created_at' => $data['requested_at'] ?? $row->checked_at, 'state' => $state,
            'database' => $data['database'] ?? null, 'directory' => 'recoveries/'.$id,
            'message' => match ($state) {
                'queued' => 'Menunggu worker backup.',
                'processing' => 'Memeriksa arsip dan memulihkan database serta berkas.',
                'completed' => 'Pemulihan selesai di ruang terpisah. Operator VPS dapat meninjau hasil sebelum mengaktifkannya.',
                default => 'Pemulihan belum berhasil. Periksa log worker dengan ID ini, kunci backup, kapasitas disk dan izin CREATE DATABASE. Data aktif tidak ditimpa; hasil parsial mungkin perlu dibersihkan operator.',
            }];
    }

    public function busy(): bool
    {
        return DB::table('system_health_signals')->where('group', 'backup_restores')->whereIn('status', ['queued', 'processing'])
            ->where('checked_at', '>=', now()->subMinutes(70))->get()->contains(fn ($row) => in_array($this->operation($row)['state'], ['queued', 'processing'], true));
    }

    public function request(int $adminId, ?string $snapshot, ?UploadedFile $upload): void
    {
        if (! app(PlatformBackup::class)->configured()) {
            throw ValidationException::withMessages(['backup' => 'Backup belum dikonfigurasi. Periksa kunci, MySQL dan worker backup.']);
        }
        $id = (string) Str::uuid();
        $stage = $this->input($id);
        $lock = null;
        try {
            if ($snapshot !== null) {
                $lock = fopen($this->root().'/.backup.lock', 'c');
                if (! flock($lock, LOCK_SH | LOCK_NB)) {
                    throw ValidationException::withMessages(['backup' => 'Snapshot sedang diperbarui. Tunggu hingga backup selesai.']);
                }
                $source = $this->snapshot($snapshot);
                if (! copy($source, $stage)) {
                    throw new RuntimeException('Salinan arsip tidak dapat dibuat.');
                }
                $name = $snapshot;
            } else {
                if (! $upload) {
                    throw ValidationException::withMessages(['file' => 'Pilih arsip backup ZIP.']);
                }
                $upload->move(dirname($stage), basename($stage));
                $name = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $upload->getClientOriginalName()))), 0, 120);
            }
            chmod($stage, 0600);
            DB::transaction(function () use ($adminId, $id, $name): void {
                DB::table('system_health_signals')->insertOrIgnore(['name' => 'backup_restore_lock', 'group' => 'internal', 'label' => 'Pengunci pemulihan', 'status' => 'idle', 'details' => '{}', 'checked_at' => now()]);
                DB::table('system_health_signals')->where('name', 'backup_restore_lock')->lockForUpdate()->first();
                if ($this->busy()) {
                    throw ValidationException::withMessages(['backup' => 'Masih ada pemulihan berjalan. Tunggu sampai selesai.']);
                }
                $data = ['id' => $id, 'source' => $name, 'requested_by' => $adminId, 'requested_at' => now()->toIso8601String(),
                    'deadline' => now()->addMinutes(15)->toIso8601String(), 'database' => 'dt_restore_'.str_replace('-', '', $id)];
                DB::table('system_health_signals')->insert(['name' => 'restore_'.$id, 'group' => 'backup_restores', 'label' => 'Pemulihan backup', 'status' => 'queued', 'details' => json_encode($data, JSON_THROW_ON_ERROR), 'checked_at' => now()]);
                Queue::connection('backups')->push(new RestorePlatformBackup($id));
            }, 3);
        } catch (\Throwable $error) {
            $this->cleanInput($id);
            throw $error;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function input(string $id): string
    {
        abort_unless(Str::isUuid($id), 404);

        return $this->root().'/.restore-input-'.$id.'.zip';
    }

    public function cleanInput(string $id): void
    {
        $path = $this->input($id);
        if (is_file($path) && ! is_link($path)) {
            unlink($path);
        }
    }

    public function start(string $id): ?array
    {
        return DB::transaction(function () use ($id): ?array {
            $row = DB::table('system_health_signals')->where('name', 'restore_'.$id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'queued') {
                return null;
            }
            $data = json_decode($row->details, true);
            if (Carbon::parse($data['deadline'])->lte(now())) {
                $this->finish($id, false);
                $this->cleanInput($id);

                return null;
            }
            $data['deadline'] = now()->addMinutes(70)->toIso8601String();
            DB::table('system_health_signals')->where('name', 'restore_'.$id)->update(['status' => 'processing', 'details' => json_encode($data, JSON_THROW_ON_ERROR), 'checked_at' => now()]);

            return $data;
        }, 3);
    }

    public function destination(string $id): string
    {
        abort_unless(Str::isUuid($id), 404);
        $root = $this->root();
        $parent = $root.'/recoveries';
        if (is_link($parent)) {
            throw new RuntimeException('Direktori pemulihan tidak valid.');
        }
        File::ensureDirectoryExists($parent, 0700);
        if (dirname(realpath($parent)) !== $root) {
            throw new RuntimeException('Direktori pemulihan di luar penyimpanan backup.');
        }

        return $parent.'/'.$id;
    }

    public function finish(string $id, bool $success): void
    {
        DB::table('system_health_signals')->where('name', 'restore_'.$id)->whereIn('status', ['queued', 'processing'])->update(['status' => $success ? 'completed' : 'failed', 'checked_at' => now()]);
    }
}
