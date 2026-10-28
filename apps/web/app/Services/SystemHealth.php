<?php

namespace App\Services;

use App\Jobs\CheckSystemQueue;
use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SystemHealth
{
    public function record(string $name, string $group, string $label, string $status, array $details = [], ?string $storeId = null, ?string $observedAt = null): void
    {
        DB::table('system_health_signals')->updateOrInsert(['name' => $name], [
            'group' => $group, 'label' => mb_substr($label, 0, 180), 'status' => $status,
            'store_id' => $storeId, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'observed_at' => $observedAt ? Carbon::parse($observedAt)->utc()->format('Y-m-d H:i:s') : null, 'checked_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function workerLoop(Looping $event): void
    {
        $connection = config('queue.default');
        $queue = config('queue.connections.'.$connection.'.queue', 'default');
        if ($event->connectionName !== $connection || ! in_array($queue, explode(',', $event->queue), true)) {
            return;
        }
        try {
            if (Cache::add('system-health:worker-write', true, 30)) {
                $this->record('queue_worker', 'core', 'Queue worker', 'ok', ['connection' => $connection, 'queue' => $queue], observedAt: now()->toIso8601String());
            }
        } catch (\Throwable) {
            // Monitoring cannot stop business workers when its own storage is unavailable.
        }
    }

    public function schedulerTick(): void
    {
        $this->record('scheduler', 'core', 'Scheduler', 'ok', observedAt: now()->toIso8601String());
        $this->collect();
    }

    public function collect(): bool
    {
        $token = (string) Str::uuid();
        $locked = DB::transaction(function () use ($token): bool {
            DB::table('system_health_signals')->insertOrIgnore(['name' => 'collector_lock', 'group' => 'internal', 'label' => 'Collector', 'status' => 'idle', 'details' => '{}', 'checked_at' => now()]);
            $lease = DB::table('system_health_signals')->where('name', 'collector_lock')->lockForUpdate()->first();
            if ($lease->status === 'busy' && Carbon::parse($lease->checked_at)->gt(now()->subSeconds(45))) {
                return false;
            }
            $this->record('collector_lock', 'internal', 'Collector', 'busy', ['token' => $token]);

            return true;
        });
        if (! $locked) {
            return false;
        }
        try {
            $this->record('database', 'core', 'Database aplikasi', 'ok', ['message' => 'Database menerima pencatatan pemeriksaan.']);
            try {
                $ok = true;
                foreach (array_unique([config('cache.default'), config('suppliers.cache_store', config('cache.default'))]) as $store) {
                    $cache = Cache::store($store);
                    $key = 'system-health:cache:'.Str::uuid();
                    try {
                        $cache->put($key, 'probe', 30);
                        $ok = $cache->get($key) === 'probe' && $ok;
                    } finally {
                        $cache->forget($key);
                    }
                }
                $this->record('cache', 'core', 'Cache aplikasi', $ok ? 'ok' : 'error', ['message' => $ok ? 'Cache aplikasi dan cache pemasok berhasil dibaca dan ditulis.' : 'Cache belum mengembalikan nilai yang ditulis.']);
            } catch (\Throwable) {
                $this->record('cache', 'core', 'Cache aplikasi', 'error', ['message' => 'Periksa koneksi cache aplikasi dan cache pemasok (Redis).']);
            }
            $manager = app(BotManager::class);
            if (! $manager->configured()) {
                $this->record('bot_manager', 'core', 'Bot manager', 'unknown', ['message' => 'Koneksi manager belum dikonfigurasi administrator.']);
            } else {
                try {
                    $response = Http::withToken(config('services.bot_manager.token'))->acceptJson()->withoutRedirecting()->connectTimeout(1)->timeout(2)
                        ->get(rtrim(config('services.bot_manager.url'), '/').'/health');
                    $ok = $response->successful() && $response->json('status') === 'SUCCESS' && is_int($response->json('workers'));
                    $this->record('bot_manager', 'core', 'Bot manager', $ok ? 'ok' : 'error', ['workers' => $ok ? max(0, $response->json('workers')) : null,
                        'message' => $ok ? 'Manager menerima pemeriksaan. Jumlah worker tidak membuktikan WhatsApp terhubung.' : 'Periksa proses manager, port loopback dan kecocokan token.']);
                } catch (\Throwable) {
                    $this->record('bot_manager', 'core', 'Bot manager', 'error', ['message' => 'Manager tidak menjawab dalam batas waktu. Periksa layanan PM2.']);
                }
            }
            $this->collectHanshop();
            $this->collectStores();
            try {
                $this->enqueueProbe();
            } catch (\Throwable) {
                $this->record('queue_probe', 'internal', 'Uji antrean', 'error', ['pending' => false, 'message' => 'Uji antrean tidak dapat dikirim. Periksa driver queue.']);
            }
            DB::table('system_webhook_health')->where('bucket', '<', now()->subDays(7))->delete();
            $this->notifyTransitions();

            return true;
        } finally {
            DB::table('system_health_signals')->where('name', 'collector_lock')->where('details->token', $token)->update(['status' => 'idle']);
        }
    }

    public function enqueueProbe(): void
    {
        $connection = config('queue.default');
        if (in_array(config('queue.connections.'.$connection.'.driver'), ['sync', 'null', 'deferred', 'background'], true)) {
            return;
        }
        DB::transaction(function () use ($connection): void {
            $row = DB::table('system_health_signals')->where('name', 'queue_probe')->lockForUpdate()->first();
            $data = $row ? json_decode($row->details, true) : [];
            if (($data['pending'] ?? false) === true) {
                return;
            }
            $token = (string) Str::uuid();
            $this->record('queue_probe', 'internal', 'Uji antrean', 'unknown', ['token' => $token, 'pending' => true, 'queued_at' => now()->toIso8601String()]);
            CheckSystemQueue::dispatch($token)->onConnection($connection)->onQueue(config('queue.connections.'.$connection.'.queue', 'default'))->afterCommit();
        });
    }

    private function collectHanshop(): void
    {
        $url = rtrim((string) config('services.hanshop.url'), '/');
        $parsed = parse_url($url);
        if (! is_array($parsed) || ! in_array($parsed['scheme'] ?? '', ['http', 'https'], true) || empty($parsed['host']) || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])) {
            $this->record('hanshop_api', 'core', 'API Hanshop', 'unknown', ['message' => 'Alamat API Hanshop belum dikonfigurasi administrator.']);

            return;
        }
        try {
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(1)->timeout(3)->get($url.'/health/ready');
            $ok = $response->successful() && $response->json('data.ready') === true;
            $this->record('hanshop_api', 'core', 'API Hanshop', $ok ? 'ok' : 'error', ['message' => $ok ? 'API Hanshop serta dependensi database dan Redis siap.' : 'API Hanshop belum siap. Periksa layanan API, database dan Redis.']);
        } catch (\Throwable) {
            $this->record('hanshop_api', 'core', 'API Hanshop', 'error', ['message' => 'API Hanshop tidak menjawab dalam batas waktu. Periksa layanan dan koneksi server.']);
        }
    }

    public function completeProbe(string $token): void
    {
        DB::transaction(function () use ($token): void {
            $row = DB::table('system_health_signals')->where('name', 'queue_probe')->lockForUpdate()->first();
            $data = $row ? json_decode($row->details, true) : [];
            if (($data['token'] ?? null) !== $token || ! ($data['pending'] ?? false)) {
                return;
            }
            $this->record('queue_probe', 'internal', 'Uji antrean', 'ok', ['pending' => false, 'queued_at' => $data['queued_at'], 'received_at' => now()->toIso8601String()], observedAt: now()->toIso8601String());
        });
    }

    public function failProbe(string $token): void
    {
        DB::transaction(function () use ($token): void {
            $row = DB::table('system_health_signals')->where('name', 'queue_probe')->lockForUpdate()->first();
            $data = $row ? json_decode($row->details, true) : [];
            if (($data['token'] ?? null) !== $token || ! ($data['pending'] ?? false)) {
                return;
            }
            $this->record('queue_probe', 'internal', 'Uji antrean', 'error', ['pending' => false, 'message' => 'Job pemeriksaan gagal. Periksa log worker; pemeriksaan berikutnya membuat satu uji baru.']);
        });
    }

    public function retryProbe(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_health_signals')->where('name', 'queue_probe')->lockForUpdate()->first();
            $data = $row ? json_decode($row->details, true) : [];
            $delayed = ($data['pending'] ?? false) && Carbon::parse($data['queued_at'])->lt(now()->subMinutes(3));
            $driver = config('queue.connections.'.config('queue.default').'.driver');
            if (! $row || (! $delayed && $row->status !== 'error') || in_array($driver, ['sync', 'null', 'deferred', 'background'], true)) {
                throw ValidationException::withMessages(['queue' => 'Uji ulang tersedia jika pemeriksaan antrean gagal atau menunggu lebih dari 3 menit.']);
            }
            $this->record('queue_probe', 'internal', 'Uji antrean', 'unknown', ['pending' => false]);
        });
        try {
            $this->enqueueProbe();
        } catch (\Throwable) {
            $this->record('queue_probe', 'internal', 'Uji antrean', 'error', ['pending' => false, 'message' => 'Uji antrean tidak dapat dikirim. Periksa driver queue.']);
            throw ValidationException::withMessages(['queue' => 'Uji antrean belum dapat dikirim. Periksa driver dan layanan penyimpanannya.']);
        }
    }

    private function activeStores(): Builder
    {
        return Store::whereNull('admin_suspended_at')->where(fn ($q) => $q->where('status', 'provisioning')->orWhere(fn ($s) => $s->whereIn('status', ['active', 'grace'])->where('expires_at', '>', now()->subDays(7))));
    }

    private function collectStores(): void
    {
        $at = now()->format('Y-m-d H:i:s');
        $this->activeStores()->whereNotNull('bot_connection_id')->select(['id', 'name', 'bot_connection_id', 'category', 'status', 'expires_at', 'is_trial'])->chunkById(100, function ($stores): void {
            foreach ($stores as $store) {
                if (! $store->canConnectBot()) {
                    continue;
                }
                try {
                    $state = Cache::get('bot:'.$store->id.':state');
                } catch (\Throwable) {
                    $state = null;
                }
                $matches = is_array($state) && ($state['connection_id'] ?? null) === $store->bot_connection_id;
                $last = $matches ? ($state['updated_at'] ?? null) : null;
                try {
                    $parsed = is_string($last) ? Carbon::parse($last) : null;
                    $fresh = $parsed && $parsed->between(now()->subSeconds(120), now()->addSeconds(15));
                    $last = $parsed?->toIso8601String();
                } catch (\Throwable) {
                    $fresh = false;
                    $last = null;
                }
                $code = $matches && in_array($state['status'] ?? null, ['CONNECTED', 'CONNECTING', 'PAIRING', 'DISCONNECTED', 'STOPPED', 'EXPIRED', 'ERROR', 'LOGGED_OUT'], true) ? $state['status'] : 'UNKNOWN';
                $status = ! $fresh ? 'unknown' : ($code === 'CONNECTED' ? 'ok' : (in_array($code, ['CONNECTING', 'PAIRING'], true) ? 'warning' : 'error'));
                $this->record('bot:'.$store->id, 'bots', $store->name, $status, ['source' => 'WhatsApp toko', 'state' => $code,
                    'message' => ! $fresh ? 'Heartbeat WhatsApp belum tersedia atau sudah usang. Periksa manager/worker dan cache.' : ($status === 'ok' ? 'WhatsApp terhubung.' : 'Buka koneksi WhatsApp toko untuk memeriksa atau memasangkan ulang.')], $store->id, $last);
            }
        });
        SupplierConnection::with('store:id,name,status,expires_at')->where('enabled', true)->whereIn('store_id', $this->activeStores()->select('id'))->chunkById(100, function ($connections): void {
            foreach ($connections as $connection) {
                $status = 'ok';
                $message = 'Snapshot katalog tersedia.';
                $observed = $connection->synced_at?->toIso8601String();
                if ($connection->last_error || $connection->catalog_error || $connection->catalog_status === 'failed') {
                    $status = 'error';
                    $message = 'Sinkronisasi gagal. Periksa pengaturan pemasok dan log yang aman; katalog lama dipertahankan.';
                } elseif (! $connection->synced_at) {
                    $status = 'unknown';
                    $message = 'Belum ada snapshot katalog berhasil. Siapkan koneksi dan jalankan sinkronisasi.';
                } elseif ($connection->kind === 'otomax') {
                    try {
                        $config = $connection->catalog_config;
                    } catch (\Throwable) {
                        $config = [];
                    }
                    if (! ($config['automatic'] ?? false)) {
                        $message = 'Pembaruan otomatis tidak aktif. Snapshot tidak dianggap sebagai bukti sinkronisasi berkala.';
                        $status = 'unknown';
                    } elseif (! $connection->catalog_next_sync_at || $connection->catalog_next_sync_at->lt(now()->subMinutes(5))) {
                        $status = 'warning';
                        $message = 'Jadwal sinkronisasi terlambat. Periksa scheduler dan queue.';
                    }
                } elseif ($connection->synced_at->lt(now()->subMinutes(10))) {
                    $status = 'warning';
                    $message = 'Snapshot katalog lebih dari 10 menit. Periksa scheduler, cache dan koneksi pemasok.';
                }
                $this->record('supplier:'.$connection->id, 'suppliers', $connection->store->name, $status,
                    ['source' => $connection->kind === 'otomax' ? 'OtomaX · '.$connection->name : ucfirst($connection->kind), 'message' => $message], $connection->store_id, $observed);
            }
        });
        DB::table('system_health_signals')->whereIn('group', ['bots', 'suppliers'])->where('checked_at', '<', $at)->delete();
    }

    public function overview(): array
    {
        $rows = DB::table('system_health_signals')->where('group', 'core')->get()->keyBy('name');
        $definitions = ['database' => ['Database aplikasi', 'Jalankan pemeriksaan untuk mencatat akses database.'], 'cache' => ['Cache aplikasi', 'Periksa konfigurasi cache aplikasi.'],
            'scheduler' => ['Scheduler', 'Jalankan satu schedule:work melalui PM2 atau satu cron schedule:run.'], 'queue_worker' => ['Queue worker', 'Periksa proses queue:work dan queue yang dilayaninya.'],
            'bot_manager' => ['Bot manager', 'Periksa layanan bot manager dan port loopback.'], 'hanshop_api' => ['API Hanshop', 'Periksa layanan API Hanshop dan dependensinya.']];
        $probe = DB::table('system_health_signals')->where('name', 'queue_probe')->first();
        $probeData = $probe ? json_decode($probe->details, true) : [];
        $services = [];
        $sync = in_array(config('queue.connections.'.config('queue.default').'.driver'), ['sync', 'null', 'deferred', 'background'], true);
        foreach ($definitions as $name => [$label, $action]) {
            $row = $rows->get($name);
            $data = $row ? json_decode($row->details, true) : [];
            $status = $row?->status ?? 'unknown';
            if ($row && Carbon::parse($row->checked_at)->lt(now()->subSeconds($name === 'queue_worker' ? 300 : 180))) {
                $status = 'warning';
                $data['message'] = 'Bukti aktivitas terakhir sudah usang. '.$action;
            }
            if ($name === 'queue_worker' && $sync) {
                $status = 'warning';
                $data['message'] = 'Queue belum memakai worker background persisten. Gunakan driver database atau Redis untuk operasional.';
            }
            if ($name === 'queue_worker' && ! $sync && ($probeData['pending'] ?? false) && Carbon::parse($probeData['queued_at'])->lt(now()->subMinutes(3))) {
                $status = 'warning';
                $data['message'] = 'Uji antrean belum diproses lebih dari 3 menit. Periksa worker dan antrean yang tertahan.';
            }
            if ($name === 'queue_worker' && ! $sync && $probe?->status === 'error') {
                $status = 'error';
                $data['message'] = $probeData['message'] ?? 'Uji antrean gagal. Periksa layanan queue.';
            }
            $services[] = ['id' => $name, 'label' => $label, 'status' => $status, 'checked_at' => $row ? Carbon::parse($row->checked_at)->toIso8601String() : null,
                'message' => $data['message'] ?? ($status === 'ok' ? 'Heartbeat proses diterima.' : 'Belum ada bukti aktivitas. '.$action), 'workers' => $data['workers'] ?? null];
        }

        $queue = $this->queueMetrics();
        $issues = DB::table('system_health_signals')->whereIn('group', ['bots', 'suppliers'])->where(fn ($q) => $q->where('status', '!=', 'ok')->orWhere('checked_at', '<', now()->subMinutes(3)))->count();
        $webhooks = DB::table('system_webhook_health')->where('bucket', '>=', now()->subDay());

        return ['services' => $services, 'queue' => [...$queue, 'probe_retry_available' => ! $sync && ($probe?->status === 'error' || (($probeData['pending'] ?? false) && Carbon::parse($probeData['queued_at'])->lt(now()->subMinutes(3)))), 'probe_pending' => (bool) ($probeData['pending'] ?? false), 'probe_queued_at' => $probeData['queued_at'] ?? null, 'probe_received_at' => $probeData['received_at'] ?? null, 'probe_status' => $probe?->status ?? 'unknown', 'probe_message' => $probeData['message'] ?? null],
            'issues' => $issues, 'webhook_errors' => (clone $webhooks)->where(fn ($q) => $q->where('http_status', '>=', 300)->orWhereIn('outcome', ['review', 'error']))->sum('requests'),
            'webhook_requests' => (clone $webhooks)->sum('requests'), 'generated_at' => now()->toIso8601String()];
    }

    public function queueMetrics(): array
    {
        try {
            return $this->readQueueMetrics();
        } catch (\Throwable) {
            return ['connection' => config('queue.default'), 'driver' => config('queue.connections.'.config('queue.default').'.driver'),
                'name' => config('queue.connections.'.config('queue.default').'.queue', 'default'), 'ready' => null, 'reserved' => null, 'delayed' => null,
                'oldest_at' => null, 'failed' => null, 'unavailable' => true];
        }
    }

    private function readQueueMetrics(): array
    {
        $connection = config('queue.default');
        $driver = config('queue.connections.'.$connection.'.driver');
        $queue = config('queue.connections.'.$connection.'.queue', 'default');
        $result = ['connection' => $connection, 'driver' => $driver, 'name' => $queue, 'ready' => null, 'reserved' => null, 'delayed' => null, 'oldest_at' => null, 'failed' => in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true) ? DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'))->count() : 0];
        if ($driver === 'database') {
            $jobs = DB::connection(config('queue.connections.'.$connection.'.connection'))->table(config('queue.connections.'.$connection.'.table', 'jobs'))->where('queue', $queue);
            $now = now()->timestamp;
            $result['ready'] = (clone $jobs)->whereNull('reserved_at')->where('available_at', '<=', $now)->count();
            $result['reserved'] = (clone $jobs)->whereNotNull('reserved_at')->count();
            $result['delayed'] = (clone $jobs)->whereNull('reserved_at')->where('available_at', '>', $now)->count();
            $oldest = (clone $jobs)->whereNull('reserved_at')->where('available_at', '<=', $now)->min('available_at');
            $result['oldest_at'] = $oldest ? Carbon::createFromTimestampUTC($oldest)->toIso8601String() : null;
        }
        if ($driver === 'redis') {
            try {
                $worker = Queue::connection($connection);
                $result['ready'] = $worker->pendingSize($queue);
                $result['reserved'] = $worker->reservedSize($queue);
                $result['delayed'] = $worker->delayedSize($queue);
            } catch (\Throwable) {
                $result['unavailable'] = true;
            }
        }

        return $result;
    }

    private function notifyTransitions(): void
    {
        $overview = $this->overview();
        $bad = array_column(array_filter($overview['services'], fn ($s) => in_array($s['status'], ['error', 'warning'], true)), 'id');
        if ($overview['issues']) {
            $bad[] = 'stores';
        }
        if ($overview['webhook_errors']) {
            $bad[] = 'webhooks';
        }
        if ($overview['queue']['failed']) {
            $bad[] = 'failed_jobs';
        }
        if (($overview['queue']['unavailable'] ?? false) || ($overview['queue']['oldest_at'] && Carbon::parse($overview['queue']['oldest_at'])->lt(now()->subMinutes(5)))) {
            $bad[] = 'queue_backlog';
        }
        $fingerprint = implode(',', $bad);
        $row = DB::table('system_health_signals')->where('name', 'alert_state')->first();
        $previous = $row ? json_decode($row->details, true) : null;
        if (($previous['fingerprint'] ?? '') === $fingerprint) {
            return;
        }
        $this->record('alert_state', 'internal', 'Status pemberitahuan', $bad ? 'warning' : 'ok', ['fingerprint' => $fingerprint]);
        User::where('role', 'admin')->each(function ($admin) use ($bad): void {
            app(PanelNotifications::class)->send($admin, $bad ? 'Kesehatan sistem perlu diperiksa' : 'Kesehatan sistem pulih',
                $bad ? 'Terdapat layanan, koneksi toko atau antrean yang perlu diperiksa pada halaman Kesehatan sistem.' : 'Indikator gangguan yang dipantau sudah pulih.', $bad ? 'warning' : 'success', '/admin/system-health', event: 'health:'.Str::uuid());
        });
    }
}
