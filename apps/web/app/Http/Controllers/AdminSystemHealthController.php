<?php

namespace App\Http\Controllers;

use App\Services\BackupRequests;
use App\Services\FailedJobDiagnostics;
use App\Services\SystemHealth;
use App\Support\ListQuery;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AdminSystemHealthController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user('web')?->role === 'admin', 403);
    }

    public function index(Request $request, SystemHealth $health): Response
    {
        $this->authorizeAdmin($request);
        $tab = in_array($request->query('tab'), ['bots', 'suppliers', 'webhooks', 'queue', 'failed'], true) ? $request->query('tab') : 'bots';
        $list = new ListQuery($request, ['status' => ['ok', 'warning', 'error', 'unknown']], 10);
        try {
            $listing = [...$this->listing($request, $tab, $list->filters), 'listingUnavailable' => false];
        } catch (QueryException) {
            $listing = ['items' => [], 'pagination' => $this->emptyMeta($list->filters['per_page']), 'listingUnavailable' => true];
        }

        return Inertia::render('Admin/SystemHealth', ['backup' => fn () => app(BackupRequests::class)->status(), 'overview' => fn () => $health->overview(), 'tab' => $tab, 'filters' => $list->filters,
            ...$listing]);
    }

    public function backup(Request $request, BackupRequests $requests): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $requests->request((int) $request->user('web')->id);

        return back()->with('success', 'Permintaan backup diterima. Status diperbarui otomatis sampai selesai.');
    }

    public function refresh(Request $request, SystemHealth $health): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $completed = $health->collect();

        return back()->with($completed ? 'success' : 'error', $completed ? 'Pemeriksaan selesai. Uji antrean diproses oleh worker; heartbeat scheduler tidak diubah oleh pemeriksaan manual.' : 'Pemeriksaan lain masih berjalan. Tunggu sebentar lalu perbarui data.');
    }

    public function retryQueue(Request $request, SystemHealth $health): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $health->retryProbe();

        return back()->with('success', 'Satu job pemeriksaan baru dikirim. Transaksi pelanggan tidak dijalankan ulang.');
    }

    private function listing(Request $request, string $tab, array $filters): array
    {
        if (in_array($tab, ['bots', 'suppliers'], true)) {
            $query = DB::table('system_health_signals')->where('group', $tab);
            $search = ['label'];
        } elseif ($tab === 'webhooks') {
            $query = DB::table('system_webhook_health')->where('bucket', '>=', now()->subDay())
                ->select(['provider', 'http_status', 'outcome'])->selectRaw('SUM(requests) AS request_count, MAX(last_seen_at) AS last_seen_at')
                ->groupBy(['provider', 'http_status', 'outcome']);
            $search = ['provider'];
        } elseif ($tab === 'failed') {
            if (! in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
                return ['items' => [], 'pagination' => $this->emptyMeta($filters['per_page'])];
            }
            $query = DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'))->select(['id', 'uuid', 'connection', 'queue', 'failed_at'])
                ->selectRaw('SUBSTR(payload, 1, 32768) AS diagnostic_payload, SUBSTR(exception, 1, 4096) AS diagnostic_exception');
            $search = ['queue', 'uuid'];
        } else {
            $connection = config('queue.default');
            if (config('queue.connections.'.$connection.'.driver') !== 'database') {
                return ['items' => [], 'pagination' => $this->emptyMeta($filters['per_page'])];
            }
            $query = DB::connection(config('queue.connections.'.$connection.'.connection'))->table(config('queue.connections.'.$connection.'.table', 'jobs'))
                ->where('queue', config('queue.connections.'.$connection.'.queue', 'default'))->select(['id', 'queue', 'attempts', 'created_at', 'available_at', 'reserved_at']);
            $search = ['queue'];
        }
        if ($filters['q'] !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(function ($nested) use ($search, $pattern): void {
                foreach ($search as $column) {
                    $nested->orWhereRaw($nested->getGrammar()->wrap($column)." LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        if (in_array($tab, ['bots', 'suppliers'], true) && $filters['status'] !== '') {
            if ($filters['status'] === 'unknown') {
                $query->where(fn ($q) => $q->where('status', 'unknown')->orWhere('checked_at', '<', now()->subMinutes(3)));
            } else {
                $query->where('status', $filters['status'])->where('checked_at', '>=', now()->subMinutes(3));
            }
        }
        $query->orderByDesc(match ($tab) {
            'bots', 'suppliers' => 'checked_at', 'webhooks' => 'last_seen_at', 'failed' => 'failed_at', default => 'created_at'
        });
        if ($tab === 'webhooks') {
            $query->orderBy('provider')->orderBy('http_status')->orderBy('outcome');
        } else {
            $query->orderBy(in_array($tab, ['bots', 'suppliers'], true) ? 'name' : 'id');
        }
        $raw = $request->query('page', 1);
        $number = is_scalar($raw) && ctype_digit((string) $raw) ? max(1, min(1000000, (int) $raw)) : 1;
        $page = (clone $query)->paginate($filters['per_page'], ['*'], 'page', $number)->withQueryString();
        if ($number > $page->lastPage()) {
            $page = $query->paginate($filters['per_page'], ['*'], 'page', $page->lastPage())->withQueryString();
        }

        return ['items' => $page->getCollection()->map(function ($row) use ($tab): array {
            if (in_array($tab, ['bots', 'suppliers'], true)) {
                $data = json_decode($row->details, true);
                $stale = Carbon::parse($row->checked_at)->lt(now()->subMinutes(3));

                return ['id' => $row->name, 'label' => $row->label, 'source' => $data['source'] ?? '', 'status' => $stale ? 'unknown' : $row->status,
                    'message' => $stale ? 'Hasil pemantauan sudah usang. Periksa scheduler atau jalankan pemeriksaan.' : ($data['message'] ?? ''), 'state' => $data['state'] ?? null,
                    'observed_at' => $row->observed_at ? Carbon::parse($row->observed_at)->toIso8601String() : null,
                    'checked_at' => Carbon::parse($row->checked_at)->toIso8601String(), 'url' => Str::isUuid($row->store_id) ? '/admin/stores/'.$row->store_id : null];
            }
            if ($tab === 'webhooks') {
                $code = (int) $row->http_status;
                $status = $code >= 500 || $code === 404 ? 'error' : ($code >= 300 || in_array($row->outcome, ['review', 'error'], true) ? 'warning' : ($row->outcome === 'ignored' ? 'unknown' : 'ok'));
                $message = match (true) {
                    $code === 404 => 'Periksa host/domain, rute callback dan keberadaan toko/koneksi.',
                    in_array($code, [401, 403]) => 'Periksa secret/token, signature dan whitelist IP; jangan menonaktifkan validasi.',
                    $code === 422 => 'Periksa struktur payload, referensi pesanan dan penangkap jawaban.',
                    $code === 429 => 'Batas permintaan tercapai. Periksa lonjakan dan pengulangan callback.',
                    $code >= 500 => 'Periksa log aplikasi dan layanan pendukung pada waktu ini.',
                    $code >= 300 && $code < 400 => 'Callback diarahkan ulang. Gunakan URL HTTPS endpoint final tanpa redirect.',
                    $row->outcome === 'review' => 'Callback diterima tetapi memerlukan pemeriksaan. Periksa transaksi/penangkap jawaban.',
                    $row->outcome === 'ignored' => 'Callback diabaikan; belum tentu ada transaksi yang cocok.',
                    default => 'Endpoint menerima callback. Ini bukan bukti transaksi pemasok telah sukses.',
                };

                return ['id' => $row->provider.':'.$code.':'.$row->outcome, 'label' => ucfirst($row->provider), 'source' => 'HTTP '.$code.' · '.$row->outcome, 'status' => $status, 'message' => $message,
                    'count' => (int) $row->request_count, 'observed_at' => Carbon::parse($row->last_seen_at)->toIso8601String(), 'url' => null];
            }
            if ($tab === 'failed') {
                $diagnostic = app(FailedJobDiagnostics::class)->describe($row->diagnostic_payload, $row->diagnostic_exception);

                return ['id' => 'failed:'.$row->id, 'label' => $diagnostic['process'], 'reference' => 'Job gagal #'.$row->id, 'source' => $row->connection.' / '.$row->queue, 'status' => 'error',
                    'message' => $diagnostic['reason'], 'impact' => $diagnostic['impact'], 'next_steps' => $diagnostic['next_steps'],
                    'observed_at' => Carbon::parse($row->failed_at)->toIso8601String(), 'url' => null];
            }
            $reserved = $row->reserved_at !== null;
            $delayed = $row->available_at > now()->timestamp;
            $retryAfter = (int) config('queue.connections.'.config('queue.default').'.retry_after', 240);
            $stuck = $reserved ? $row->reserved_at < now()->subSeconds($retryAfter)->timestamp : (! $delayed && $row->available_at < now()->subMinutes(5)->timestamp);

            return ['id' => 'queue:'.$row->id, 'label' => 'Job #'.$row->id, 'source' => $row->queue.' · percobaan '.(int) $row->attempts, 'status' => $stuck ? 'warning' : 'ok',
                'message' => $reserved ? ($stuck ? 'Waktu pengambilan job sudah melewati batas. Periksa proses queue di PM2 dan hasil proses terkait sebelum mencoba ulang agar pesanan atau saldo tidak diproses dua kali.' : 'Sedang diambil worker; proses belum tentu sudah selesai.') : ($delayed ? 'Menunggu waktu eksekusi yang dijadwalkan.' : ($stuck ? 'Antrean siap lebih dari 5 menit. Administrator perlu memeriksa proses queue di PM2 dan beban antrean.' : 'Siap diproses worker.')),
                'observed_at' => Carbon::createFromTimestampUTC($row->created_at)->toIso8601String(), 'url' => null];
        }), 'pagination' => ListQuery::meta($page)];
    }

    private function emptyMeta(int $size): array
    {
        return ['current_page' => 1, 'last_page' => 1, 'per_page' => $size, 'total' => 0, 'from' => null, 'to' => null];
    }
}
