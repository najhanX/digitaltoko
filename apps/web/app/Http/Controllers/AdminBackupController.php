<?php

namespace App\Http\Controllers;

use App\Services\BackupRecovery;
use App\Services\BackupRequests;
use App\Support\ListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminBackupController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user('web')?->role === 'admin', 403);
    }

    private function uploadLimitKb(): int
    {
        $limits = [(int) config('backup.upload_max_mb', 512) * 1024];
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $value = trim((string) ini_get($setting));
            $bytes = (float) $value * match (strtolower(substr($value, -1))) {
                'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1,
            };
            if ($bytes > 0) {
                $limits[] = max(1, (int) floor(($bytes - ($setting === 'post_max_size' ? 65536 : 0)) / 1024));
            }
        }

        return max(1, min($limits));
    }

    public function index(Request $request, BackupRecovery $recovery, BackupRequests $backups): Response
    {
        $this->authorizeAdmin($request);
        $list = new ListQuery($request, [], 10);
        $tab = $request->query('tab') === 'restores' ? 'restores' : 'snapshots';
        $raw = $request->query('page', 1);
        $page = is_scalar($raw) && ctype_digit((string) $raw) ? min(1000000, max(1, (int) $raw)) : 1;
        if ($tab === 'restores') {
            $query = DB::table('system_health_signals')->where('group', 'backup_restores');
            if ($list->filters['q'] !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $list->filters['q']).'%';
                $query->whereRaw("details LIKE ? ESCAPE '!'", [$pattern]);
            }
            $paginator = $query->orderByDesc('checked_at')->orderBy('name')->paginate($list->filters['per_page'], ['*'], 'page', $page);
            if ($page > $paginator->lastPage()) {
                $page = $paginator->lastPage();
                $paginator = $query->paginate($list->filters['per_page'], ['*'], 'page', $page);
            }
            $paginator->setCollection($paginator->getCollection()->map(fn ($row) => $recovery->operation($row)));
        } else {
            $items = collect($recovery->snapshots())->filter(fn ($item) => str_contains(strtolower($item['name']), strtolower($list->filters['q'])))->values();
            $page = min(max(1, (int) ceil($items->count() / $list->filters['per_page'])), $page);
            $paginator = new LengthAwarePaginator($items->forPage($page, $list->filters['per_page'])->values(), $items->count(), $list->filters['per_page'], $page);
        }

        return Inertia::render('Admin/Backups', ['backup' => fn () => $backups->status(), 'restoreBusy' => $recovery->busy(),
            'tab' => $tab, 'items' => $paginator->items(), 'filters' => $list->filters,
            'pagination' => ['current_page' => $page, 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()],
            'uploadLimitMb' => round($this->uploadLimitKb() / 1024, 1), 'retention' => max(1, (int) config('backup.keep'))]);
    }

    public function download(Request $request, string $name, BackupRecovery $recovery): BinaryFileResponse
    {
        $this->authorizeAdmin($request);

        return response()->download($recovery->snapshot($name), $name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function restore(Request $request, BackupRecovery $recovery): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['confirmation' => ['required', 'in:PULIHKAN'], 'snapshot' => ['nullable', 'string', 'max:120', 'required_without:file', 'prohibits:file'],
            'file' => ['nullable', 'file', 'extensions:zip', 'max:'.$this->uploadLimitKb(), 'required_without:snapshot']]);
        $recovery->request((int) $request->user('web')->id, $data['snapshot'] ?? null, $request->file('file'));

        return to_route('admin.backups', ['tab' => 'restores'])->with('success', 'Pemulihan masuk antrean. Status dan lokasi hasil tersedia di riwayat pemulihan.');
    }
}
