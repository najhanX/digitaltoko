<?php

namespace App\Services;

use App\Jobs\RunPlatformBackup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BackupRequests
{
    public function status(): array
    {
        $row = DB::table('system_health_signals')->where('name', 'backup_request')->first();
        $data = $row ? json_decode($row->details, true) : [];
        $data = is_array($data) ? $data : [];
        $state = $row?->status ?? 'idle';
        if (in_array($state, ['queued', 'processing'], true)) {
            try {
                $expired = ! isset($data['deadline']) || Carbon::parse($data['deadline'])->lte(now());
            } catch (\Throwable) {
                $expired = true;
            }
            if ($expired) {
                $state = 'failed';
            }
        }
        $state = in_array($state, ['queued', 'processing', 'completed', 'failed'], true) ? $state : 'idle';
        $message = match ($state) {
            'queued' => 'Menunggu worker backup. Anda boleh meninggalkan halaman ini.',
            'processing' => 'Backup sedang dibuat dan diverifikasi. Anda boleh meninggalkan halaman ini.',
            'completed' => 'Backup selesai dan tersimpan terenkripsi di server.',
            'failed' => 'Backup belum berhasil atau antrean melewati batas waktu. Periksa worker backup dan log, lalu coba kembali.',
            default => 'Database, media dan sesi WhatsApp dicadangkan ke server.',
        };

        return ['state' => $state, 'busy' => in_array($state, ['queued', 'processing'], true), 'configured' => app(PlatformBackup::class)->configured(), 'message' => $message,
            'requested_at' => $data['requested_at'] ?? null, 'finished_at' => $data['finished_at'] ?? null];
    }

    public function request(int $adminId): void
    {
        if (! app(PlatformBackup::class)->configured()) {
            throw ValidationException::withMessages(['backup' => 'Backup belum siap. Periksa BACKUP_ENABLED, kunci backup dan koneksi MySQL.']);
        }
        DB::transaction(function () use ($adminId): void {
            DB::table('system_health_signals')->insertOrIgnore(['name' => 'backup_request', 'group' => 'internal', 'label' => 'Permintaan backup', 'status' => 'idle', 'details' => '{}', 'checked_at' => now()]);
            DB::table('system_health_signals')->where('name', 'backup_request')->lockForUpdate()->first();
            if ($this->status()['busy']) {
                return;
            }
            $id = (string) Str::uuid();
            $data = ['id' => $id, 'requested_by' => $adminId, 'requested_at' => now()->toIso8601String(), 'deadline' => now()->addMinutes(15)->toIso8601String()];
            DB::table('system_health_signals')->where('name', 'backup_request')->update(['status' => 'queued', 'details' => json_encode($data, JSON_THROW_ON_ERROR), 'checked_at' => now()]);
            Queue::connection('backups')->push(new RunPlatformBackup($id));
        }, 3);
    }

    public function start(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $row = DB::table('system_health_signals')->where('name', 'backup_request')->lockForUpdate()->first();
            $data = $row ? json_decode($row->details, true) : [];
            if (! $row || ($data['id'] ?? null) !== $id || $row->status !== 'queued') {
                return false;
            }
            try {
                $expired = Carbon::parse($data['deadline'] ?? '1970-01-01')->lte(now());
            } catch (\Throwable) {
                $expired = true;
            }
            if ($expired) {
                DB::table('system_health_signals')->where('name', 'backup_request')->update(['status' => 'failed', 'checked_at' => now()]);

                return false;
            }
            $data['deadline'] = now()->addMinutes(70)->toIso8601String();
            $data['started_at'] = now()->toIso8601String();
            DB::table('system_health_signals')->where('name', 'backup_request')->update(['status' => 'processing', 'details' => json_encode($data, JSON_THROW_ON_ERROR), 'checked_at' => now()]);

            return true;
        }, 3);
    }

    public function finish(string $id, bool $success): bool
    {
        return DB::transaction(function () use ($id, $success): bool {
            $row = DB::table('system_health_signals')->where('name', 'backup_request')->lockForUpdate()->first();
            $data = $row ? json_decode($row->details, true) : [];
            if (! $row || ($data['id'] ?? null) !== $id || ! in_array($row->status, ['queued', 'processing'], true)) {
                return false;
            }
            $data['finished_at'] = now()->toIso8601String();
            DB::table('system_health_signals')->where('name', 'backup_request')->update(['status' => $success ? 'completed' : 'failed', 'details' => json_encode($data, JSON_THROW_ON_ERROR), 'checked_at' => now()]);

            return true;
        }, 3);
    }
}
