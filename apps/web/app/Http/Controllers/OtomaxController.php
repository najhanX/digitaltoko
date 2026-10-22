<?php

namespace App\Http\Controllers;

use App\Jobs\ImportOtomaxProducts;
use App\Models\OrderAttempt;
use App\Models\OtomaxEvent;
use App\Models\OtomaxImport;
use App\Models\OtomaxProfile;
use App\Models\OtomaxTicket;
use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Services\OtomaxClient;
use App\Services\OtomaxImports;
use App\Services\OtomaxParser;
use App\Services\PanelNotifications;
use App\Services\StoreRetention;
use App\Services\SupplierCache;
use App\Support\CatalogAccess;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OtomaxController extends Controller
{
    private function access(Request $request, Store $store, string $permission = 'products.view'): string
    {
        $role = CatalogAccess::authorize($request, $store, $permission);
        CatalogAccess::operational($store);
        abort_unless(CatalogAccess::supports($store, 'otomax'), 403, 'Paket toko belum mendukung sumber PPOB sendiri.');

        return $role;
    }

    private function connection(Store $store, SupplierConnection $c): void
    {
        abort_unless($c->store_id === $store->id && $c->kind === 'otomax', 404);
    }

    private function audit(Store $store, string $action): void
    {
        app(PanelNotifications::class)->forStore($store, 'Pengaturan OtomaX', $action, 'info', 'otomax:'.Str::uuid(), 'suppliers.manage');
    }

    public function index(Request $request, Store $store): Response
    {
        $role = $this->access($request, $store);
        $tab = in_array($request->query('tab'), ['responses', 'profiles', 'imports', 'tickets', 'events'], true) ? $request->query('tab') : 'connections';
        if ($tab === 'tickets') {
            $this->access($request, $store, 'suppliers.deposit');
        }
        $list = new ListQuery($request, [], 10);
        $model = match ($tab) {
            'responses','profiles' => OtomaxProfile::class,'imports' => OtomaxImport::class,'tickets' => OtomaxTicket::class,'events' => OtomaxEvent::class,default => SupplierConnection::class
        };
        $q = $model::where('store_id', $store->id);
        if ($tab === 'connections') {
            $q->where('kind', 'otomax');
        }if (in_array($tab, ['responses', 'profiles'])) {
            $q->where('type', $tab === 'responses' ? 'response' : 'import');
        }
        $search = in_array($tab, ['responses', 'profiles', 'connections']) ? ['name'] : ($tab === 'imports' ? ['filename', 'status'] : ['status']);
        $page = $list->paginate($list->apply($q, $search)->orderByDesc('created_at')->orderBy('id'));
        $rows = $page->getCollection()->map(function ($row) use ($tab) {
            if ($tab === 'connections') {
                return [...$row->only(['id', 'name', 'enabled', 'settings', 'response_profile_id', 'verified_at', 'synced_at', 'last_error']), 'member_id' => $row->credentials['member_id'] ?? '', 'response_name' => OtomaxProfile::find($row->response_profile_id)?->name, 'products' => SupplierProduct::where('connection_id', $row->id)->count()];
            }
            if ($tab === 'events') {
                $message = $row->data['message'] ?? null;

                return [...$row->only(['id', 'operation', 'status', 'reference', 'created_at']), 'message' => is_string($message) ? $message : null];
            }

            return $row->only(match ($tab) {
                'responses','profiles' => ['id', 'name', 'version', 'updated_at'], 'imports' => ['id', 'filename', 'status', 'summary', 'processed', 'error', 'created_at'], 'tickets' => ['id', 'amount', 'status', 'is_sandbox', 'result', 'created_at'],default => ['id', 'operation', 'status', 'reference', 'created_at']
            });
        });

        return Inertia::render('Owner/Otomax', ['role' => $role, 'store' => $store->only(['id', 'name', 'sandbox', 'status']), 'tab' => $tab, 'items' => $rows, 'filters' => $list->filters, 'pagination' => ListQuery::meta($page), 'defaults' => ['response' => [...OtomaxParser::defaults(), 'rules' => OtomaxParser::examples()], 'import' => OtomaxImports::defaults()], 'permissions' => collect(['manage', 'responses', 'import', 'deposit', 'balance'])->mapWithKeys(fn ($key) => [$key => CatalogAccess::has($request, $role, 'suppliers.'.$key)])]);
    }

    public function options(Request $request, Store $store): JsonResponse
    {
        $this->access($request, $store);
        $type = $request->query('type');
        $query = $type === 'connection' ? SupplierConnection::where('store_id', $store->id)->where('kind', 'otomax') : OtomaxProfile::where('store_id', $store->id)->where('type', $type === 'import' ? 'import' : 'response');
        $list = new ListQuery($request, [], 10);
        $options = $list->options($list->apply($query, ['name'])->select('id', 'name')->orderBy('name'));

        return response()->json($options)->header('Cache-Control', 'no-store');
    }

    public function profile(Request $request, Store $store, OtomaxProfile $profile): JsonResponse
    {
        $this->access($request, $store, $profile->type === 'response' ? 'suppliers.responses' : 'suppliers.import');
        abort_unless($profile->store_id === $store->id, 404);

        return response()->json([...$profile->only(['id', 'type', 'name', 'version']), 'config' => $profile->config])->header('Cache-Control', 'no-store');
    }

    public function saveProfile(Request $request, Store $store, ?OtomaxProfile $profile = null): RedirectResponse
    {
        $data = $request->validate(['type' => 'required|in:response,import', 'name' => 'required|string|max:100', 'config' => 'required|array', 'version' => 'nullable|integer']);
        $this->access($request, $store, $data['type'] === 'response' ? 'suppliers.responses' : 'suppliers.import');
        if ($profile) {
            abort_unless($profile->store_id === $store->id && $profile->type === $data['type'], 404);
        }
        $config = $data['type'] === 'response' ? app(OtomaxParser::class)->validate($data['config']) : $this->importConfig($data['config']);
        DB::transaction(function () use ($store, $profile, $data, $config) {
            StoreRetention::guard(Store::whereKey($store->id)->lockForUpdate()->firstOrFail());
            if ($profile) {
                $profile = OtomaxProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();
                abort_unless($profile->version === ($data['version'] ?? 0), 409, 'Profil telah berubah. Muat ulang sebelum menyimpan.');
                $profile->update(['name' => $data['name'], 'config' => $config, 'version' => $profile->version + 1]);
            } else {
                OtomaxProfile::create(['store_id' => $store->id, 'type' => $data['type'], 'name' => $data['name'], 'config' => $config]);
            }
        });
        $this->audit($store, 'Profil '.$data['name'].' disimpan. Pesanan lama tetap memakai versi saat pemesanan.');

        return back()->with('success', 'Profil tersimpan; berlaku untuk pesanan baru.');
    }

    public function deleteProfile(Request $request, Store $store, OtomaxProfile $profile): RedirectResponse
    {
        $this->access($request, $store, $profile->type === 'response' ? 'suppliers.responses' : 'suppliers.import');
        abort_unless($profile->store_id === $store->id, 404);
        DB::transaction(function () use ($store, $profile) {
            StoreRetention::guard(Store::whereKey($store->id)->lockForUpdate()->firstOrFail());
            abort_if(SupplierConnection::where('response_profile_id', $profile->id)->exists(), 422, 'Lepaskan profil dari koneksi terlebih dahulu.');
            $profile->delete();
        });

        return back()->with('success', 'Profil dihapus.');
    }

    public function tester(Request $request, Store $store): Response
    {
        $role = $this->access($request, $store, 'suppliers.responses');

        return Inertia::render('Owner/OtomaxTester', ['role' => $role, 'store' => $store->only(['id', 'name']), 'permissions' => ['deposit' => CatalogAccess::has($request, $role, 'suppliers.deposit'), 'responses' => true]]);
    }

    public function test(Request $request, Store $store): JsonResponse
    {
        $this->access($request, $store, 'suppliers.responses');
        $data = $request->validate(['config' => 'required|array', 'event' => 'required|in:transaction,callback,status,balance,ticket', 'text' => 'required|string|max:20000', 'query' => 'nullable|array|max:20', 'query.*' => 'nullable|string|max:20000']);
        $parser = app(OtomaxParser::class);
        $config = $parser->validate($data['config']);
        $input = empty($data['query']) ? $data['text'] : [...$data['query'], $config['message_field'] => $data['text']];

        return response()->json($parser->inspect($config, $data['event'], $input))->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, Store $store, ?SupplierConnection $connection = null): RedirectResponse
    {
        $this->access($request, $store, 'suppliers.manage');
        if ($connection) {
            $this->connection($store, $connection);
        }
        $data = $request->validate(['name' => 'required|string|max:100', 'response_profile_id' => 'required|uuid', 'member_id' => 'required|string|max:100|regex:/^[A-Za-z0-9_-]+$/', 'pin' => 'nullable|string|max:100', 'password' => 'nullable|string|max:255', 'enabled' => 'required|boolean', 'settings' => 'required|array']);
        $settings = $request->validate(['settings.base_url' => 'required|string|max:250', 'settings.transaction_path' => 'required|regex:~^/[a-zA-Z0-9/_-]{1,100}$~', 'settings.balance_path' => 'required|regex:~^/[a-zA-Z0-9/_-]{1,100}$~', 'settings.ticket_path' => 'required|regex:~^/[a-zA-Z0-9/_-]{1,100}$~', 'settings.status_path' => 'nullable|regex:~^/[a-zA-Z0-9/_-]{1,100}$~', 'settings.callback_ips' => 'required|array|min:1|max:10', 'settings.callback_ips.*' => 'required|ip', 'settings.catalog_hours' => 'required|integer|min:1|max:168', 'settings.ticket_cmd' => 'required|boolean', 'settings.price_limit_ack' => 'accepted'])['settings'];
        app(OtomaxClient::class)->endpoint($settings);
        $profile = OtomaxProfile::where('store_id', $store->id)->where('type', 'response')->findOrFail($data['response_profile_id']);
        $credentials = $connection?->credentials ?? ['callback_token' => Str::random(48)];
        foreach (['pin', 'password'] as $key) {
            if (filled($data[$key] ?? null)) {
                $credentials[$key] = $data[$key];
            }if (! filled($credentials[$key] ?? null)) {
                throw ValidationException::withMessages([$key => 'Isi '.$key.' supplier.']);
            }
        }
        $credentials['member_id'] = $data['member_id'];
        DB::transaction(function () use ($store, $connection, $data, $settings, $credentials, $profile) {
            StoreRetention::guard(Store::whereKey($store->id)->lockForUpdate()->firstOrFail());
            $c = $connection ? SupplierConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail() : new SupplierConnection(['store_id' => $store->id, 'kind' => 'otomax', 'slot' => Str::uuid()->toString()]);
            $identityChanged = $c->exists && (($c->settings['base_url'] ?? '') !== $settings['base_url'] || array_intersect_key($c->credentials ?? [], array_flip(['member_id', 'pin', 'password'])) !== array_intersect_key($credentials, array_flip(['member_id', 'pin', 'password'])));
            if ($identityChanged) {
                abort_if(OrderAttempt::whereHas('order', fn ($q) => $q->where('store_id', $store->id)->whereIn('status', ['paid', 'processing', 'pending', 'review']))->where('source', 'otomax')->where('request_snapshot', '!=', null)->get()->contains(fn ($a) => ($a->request_snapshot['connection_id'] ?? '') === $c->id), 422, 'Selesaikan transaksi berjalan sebelum mengganti akun/alamat.');
                SupplierProduct::where('connection_id', $c->id)->update(['available' => false]);
            }
            $c->fill(['name' => $data['name'], 'settings' => $settings, 'credentials' => $credentials, 'response_profile_id' => $profile->id, 'enabled' => $data['enabled'], 'verified_at' => null]);
            $c->save();
        });
        $this->audit($store, 'Koneksi OtomaX '.$data['name'].' disimpan.');

        return back()->with('success', 'Koneksi disimpan. Uji saldo dan impor katalog sebelum memetakan produk.');
    }

    public function callbackUrl(Request $request, Store $store, SupplierConnection $connection): JsonResponse
    {
        $this->access($request, $store, 'suppliers.manage');
        $this->connection($store, $connection);

        return response()->json(['url' => rtrim(config('app.url'), '/').'/api/commerce/otomax/'.$connection->id.'/'.$connection->credentials['callback_token']])->header('Cache-Control', 'private, no-store');
    }

    public function balance(Request $request, Store $store, SupplierConnection $connection): JsonResponse
    {
        $this->access($request, $store, 'suppliers.balance');
        $this->connection($store, $connection);
        abort_unless($connection->enabled, 422);
        $snapshot = app(SupplierCache::class)->remember('otomax', app(OtomaxImports::class)->fingerprint($connection).':'.($store->sandbox ? 'sandbox' : 'production'), 'balance', fn () => app(OtomaxClient::class)->send($connection, 'balance'));
        $result = $snapshot['value'];
        $balance = OtomaxParser::amount($result['fields']['saldo'] ?? null);
        abort_if($balance === null, 422, 'Saldo belum tertangkap. Sesuaikan pola jawaban saldo pada kelompok supplier.');
        $connection->update(['verified_at' => $store->sandbox ? null : now()]);

        return response()->json(['balance' => $balance, 'observed_at' => $snapshot['observed_at'], 'simulated' => $store->sandbox]);
    }

    public function ticket(Request $request, Store $store, SupplierConnection $connection): RedirectResponse
    {
        $this->access($request, $store, 'suppliers.deposit');
        $this->connection($store, $connection);
        abort_unless($connection->enabled, 422);
        $d = $request->validate(['amount' => 'required|integer|min:10000|max:100000000', 'request_id' => 'required|uuid']);
        $record = DB::transaction(function () use ($store, $connection, $d) {
            StoreRetention::guard(Store::whereKey($store->id)->lockForUpdate()->firstOrFail());

            return OtomaxTicket::firstOrCreate(['connection_id' => $connection->id, 'request_id' => $d['request_id']], ['store_id' => $store->id, 'amount' => $d['amount'], 'is_sandbox' => $store->sandbox]);
        });
        if (! $record->wasRecentlyCreated) {
            return back()->with('success', 'Permintaan tiket sebelumnya tetap tersimpan; tidak dikirim ulang.');
        }
        try {
            $result = app(OtomaxClient::class)->send($connection, 'ticket', ['amount' => $d['amount']]);
            $record->update(['status' => 'instructions', 'result' => $result['text']]);
        } catch (\Throwable) {
            $record->update(['status' => 'unknown', 'result' => 'Hasil permintaan tiket belum pasti. Periksa supplier sebelum meminta ulang.']);
        }

        return back()->with('success', 'Hasil tiket tersimpan pada riwayat. Dana tidak ditransfer otomatis.');
    }

    private function importConfig(array $config): array
    {
        $v = Validator::make($config, ['sheet' => 'nullable|string|max:100', 'header_row' => 'required|integer|min:0|max:100', 'default_available' => 'sometimes|boolean', 'grouped' => 'required|boolean', 'group_column' => 'required|regex:/^[A-Z]{1,2}$/', 'columns' => 'required|array', 'columns.sku' => 'required|regex:/^[A-Z]{1,2}$/', 'columns.name' => 'required|regex:/^[A-Z]{1,2}$/', 'columns.cost' => 'required|regex:/^[A-Z]{1,2}$/', 'columns.status' => 'nullable|regex:/^[A-Z]{1,2}$/', 'columns.category' => 'nullable|regex:/^[A-Z]{1,2}$/', 'columns.quantity' => 'nullable|regex:/^[A-Z]{1,2}$/', 'columns.service' => 'nullable|regex:/^[A-Z]{1,2}$/', 'service' => 'required|string|max:100', 'category' => 'required|string|max:100', 'thousands' => ['present', 'nullable', Rule::in(['.', ','])], 'decimal' => ['required', Rule::in(['.', ','])], 'delimiter' => ['required', Rule::in([';', ',', "\t"])], 'encoding' => 'required|in:UTF-8,Windows-1252,ISO-8859-1', 'available_values' => 'required|string|max:200', 'unavailable_values' => 'required|string|max:200', 'prefix_group' => 'required|boolean'])->validate();
        if (($v['thousands'] ?? '') === $v['decimal']) {
            throw ValidationException::withMessages(['thousands' => 'Pemisah ribuan dan desimal harus berbeda.']);
        }

        return $v;
    }

    public function preview(Request $request, Store $store, SupplierConnection $connection): JsonResponse
    {
        $this->access($request, $store, 'suppliers.import');
        $this->connection($store, $connection);
        abort_unless($connection->enabled, 422);
        $d = $request->validate(['file' => 'nullable|file|extensions:xlsx,csv|max:10240', 'config' => 'nullable|array', 'import_id' => 'nullable|uuid']);
        $config = $this->importConfig($d['config'] ?? OtomaxImports::defaults());
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('otomax/'.$store->id, 'local');
            $record = OtomaxImport::create(['store_id' => $store->id, 'connection_id' => $connection->id, 'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 200), 'file_hash' => hash_file('sha256', Storage::disk('local')->path($path)), 'payload' => ['path' => $path, 'type' => strtolower($file->getClientOriginalExtension())], 'summary' => [], 'status' => 'draft', 'expires_at' => now()->addHours(2)]);
        } else {
            $record = OtomaxImport::where('store_id', $store->id)->where('connection_id', $connection->id)->findOrFail($d['import_id'] ?? '');
        }
        abort_unless(in_array($record->status, ['draft', 'preview'], true) && $record->expires_at->isFuture(), 422, 'Pratinjau kedaluwarsa atau sudah dikirim.');
        $payload = $record->payload;
        try {
            $reader = app(OtomaxImports::class);
            $source = $reader->read(Storage::disk('local')->path($payload['path']), $payload['type'], $config);
            $result = $reader->normalize($source['rows'], $config);
        } catch (\Throwable $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }throw ValidationException::withMessages(['file' => 'Berkas tidak dapat dibaca. Gunakan XLSX/CSV dengan data nilai biasa.']);
        }
        $existing = SupplierProduct::where('connection_id', $connection->id)->pluck('cost', 'sku');
        $new = 0;
        $changed = 0;
        foreach ($result['rows'] as $row) {
            if (! $existing->has($row['sku'])) {
                $new++;
            } elseif ((int) $existing[$row['sku']] !== $row['cost']) {
                $changed++;
            }
        }
        $summary = [...$result['summary'], 'new' => $new, 'price_changed' => $changed, 'missing' => $existing->keys()->diff(array_column($result['rows'], 'sku'))->count()];
        $reader->savePreview($record, [...$payload, 'config' => $config, 'sandbox' => $store->sandbox, 'connection_hash' => $reader->fingerprint($connection), 'catalog_synced_at' => $connection->synced_at?->toISOString()], $summary, $result['rows']);

        return response()->json(['id' => $record->id, 'summary' => $summary, 'sample' => array_slice($result['rows'], 0, 50), 'errors' => array_slice($result['errors'], 0, 100), 'sheets' => $source['sheets'], 'headers' => $source['rows'][$config['header_row'] - 1] ?? [], 'config' => $config]);
    }

    public function confirm(Request $request, Store $store, OtomaxImport $import): RedirectResponse
    {
        $this->access($request, $store, 'suppliers.import');
        abort_unless($import->store_id === $store->id, 404);
        $d = $request->validate(['accept_partial' => 'required|boolean', 'missing' => 'required|in:keep,disable']);
        $dispatch = DB::transaction(function () use ($store, $import, $d) {
            StoreRetention::guard(Store::whereKey($store->id)->lockForUpdate()->firstOrFail());
            $import = OtomaxImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            if (in_array($import->status, ['queued', 'processing', 'completed'], true)) {
                return false;
            }abort_unless($import->status === 'preview' && $import->expires_at->isFuture() && ($import->summary['valid'] ?? 0) > 0, 422, 'Buat pratinjau valid terlebih dahulu.');
            abort_if(($import->summary['rejected'] ?? 0) > 0 && ! $d['accept_partial'], 422, 'Setujui impor sebagian atau perbaiki baris yang ditolak.');
            abort_if(($import->summary['rejected'] ?? 0) > 0 && $d['missing'] === 'disable', 422, 'Nonaktifkan yang hilang hanya untuk file lengkap tanpa baris ditolak.');
            $import->update(['status' => 'queued', 'payload' => [...$import->payload, 'missing' => $d['missing']]]);

            return true;
        });
        if ($dispatch) {
            try {
                ImportOtomaxProducts::dispatch($import->id);
            } catch (\Throwable) {
                $import->update(['status' => 'failed', 'error' => 'Antrean belum tersedia. Periksa worker, lalu unggah ulang untuk membuat pratinjau baru.']);
                throw ValidationException::withMessages(['file' => 'Impor tidak dapat masuk antrean. Periksa worker aplikasi.']);
            }
        }

        return back()->with('success', 'Impor masuk antrean. Pantau hasilnya di Riwayat impor.');
    }
}
