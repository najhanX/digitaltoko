<?php

namespace App\Services;

use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Support\CatalogAccess;
use App\Support\HanshopIdentity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierCatalog
{
    public function __construct(private SupplierCache $cache) {}

    public function refreshForStore(Store $store): void
    {
        if (! $store->acceptsOrders() && $store->status !== 'provisioning') {
            return;
        }
        $age = max(60, min(180, (int) config('suppliers.cache_seconds', 120)));
        $connections = SupplierConnection::with('store.owner')->where('store_id', $store->id)
            ->where('kind', '!=', 'otomax')->where('enabled', true)->whereNotNull('synced_at')->where('synced_at', '<=', now()->subSeconds($age))->get();
        foreach ($connections as $connection) {
            if (! CatalogAccess::supports($store, $connection->kind)) {
                continue;
            }
            if ($connection->synced_at->greaterThan(now()->subSeconds($this->cache->lifetime($connection->kind, 'catalog:prepaid')))) {
                continue;
            }
            foreach ($connection->catalog_modes ?? ['prepaid'] as $mode) {
                try {
                    $this->sync($connection, $mode);
                } catch (ValidationException) {
                    // A failed refresh retains the last snapshot; stale data remains unavailable for checkout.
                }
            }
        }
    }

    private function account(SupplierConnection $connection): string
    {
        return $connection->kind === 'digiflazz'
            ? json_encode(app(StoreEnvironment::class)->credentials($connection))
            : config('services.hanshop.url').':'.$connection->store->owner->hanshop_user_id.':'.($connection->store->sandbox ? 'sandbox' : 'production');
    }

    private function digi(SupplierConnection $connection, string $path, string $mode): array
    {
        $credentials = app(StoreEnvironment::class)->credentials($connection);
        $username = $credentials['username'] ?? '';
        $key = $credentials['api_key'] ?? '';
        if (! $username || ! $key) {
            throw ValidationException::withMessages(['supplier' => 'Lengkapi credential Digiflazz terlebih dahulu.']);
        }
        try {
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20)
                ->post('https://api.digiflazz.com/v1/'.$path, ['cmd' => $path === 'cek-saldo' ? 'deposit' : $mode,
                    'username' => $username, 'sign' => md5($username.$key.($path === 'cek-saldo' ? 'depo' : 'pricelist'))]);
        } catch (ConnectionException) {
            throw new SupplierFailure('API Digiflazz tidak dapat dihubungi. Periksa koneksi server lalu coba kembali.');
        }
        $data = $response->json('data');
        $rawCode = $response->json('data.rc') ?? $response->json('rc');
        $code = is_scalar($rawCode) && preg_match('/^[0-9]{2}$/D', (string) $rawCode) ? (string) $rawCode : null;
        if ($code === '83' || $response->status() === 429) {
            $message = $path === 'price-list' || $code === '83'
                ? 'Digiflazz membatasi pengecekan daftar harga ('.($code === '83' ? 'RC 83' : 'HTTP 429').'). Daftar harga hanya boleh diminta sekali setiap 5 menit per akun.'
                : 'Digiflazz sedang membatasi permintaan saldo (HTTP 429). Tunggu beberapa saat sebelum memeriksa kembali.';
            throw new SupplierFailure($message, true);
        }
        if ($code === '45') {
            throw new SupplierFailure(app(DigiflazzNetwork::class)->whitelistMessage((bool) ($credentials['testing'] ?? false), $response->json('data.message') ?? $response->json('message')));
        }
        $message = match ($code) {
            '40' => 'Parameter permintaan Digiflazz ditolak (RC 40). Periksa konfigurasi integrasi.',
            '41' => 'Signature Digiflazz ditolak (RC 41). Periksa API key sesuai mode Production atau Development.',
            '42' => 'Username Digiflazz tidak sesuai (RC 42). Periksa username pada koneksi pemasok.',
            '82' => 'Akun Digiflazz belum terverifikasi (RC 82). Selesaikan verifikasi akun Digiflazz.',
            '88' => 'Akun Digiflazz belum diizinkan mengakses API (RC 88). Periksa akun atau hubungi Digiflazz.',
            default => null,
        };
        if ($message !== null) {
            throw new SupplierFailure($message);
        }
        if (! $response->successful() || ! is_array($data) || ($code !== null && $code !== '00')) {
            throw new SupplierFailure('Permintaan Digiflazz gagal (HTTP '.$response->status().($code !== null ? ', RC '.$code : '').'). Coba kembali atau periksa layanan Digiflazz.');
        }

        return $data;
    }

    public function balance(SupplierConnection $connection): array
    {
        CatalogAccess::operational($connection->store);
        if ($connection->store->sandbox) {
            if ($connection->kind === 'digiflazz') {
                app(StoreEnvironment::class)->credentials($connection);
            }

            return ['amount' => 1000000, 'simulated' => true, 'observed_at' => now()->toIso8601String()];
        }
        $snapshot = $this->cache->remember($connection->kind, $this->account($connection), 'balance', function () use ($connection) {
            $startedAt = now()->toIso8601String();
            if ($connection->kind === 'digiflazz') {
                $data = $this->digi($connection, 'cek-saldo', 'prepaid');
                $balance = $data['deposit'] ?? null;
            } else {
                $data = app(HanshopIdentity::class)->call('supplier/balance', $this->hanshopContext($connection));
                abort_unless(($data['id_user'] ?? null) === $connection->store->owner->hanshop_user_id, 422);
                $balance = $data['balance'] ?? null;
            }
            if (! is_numeric($balance) || ! is_finite((float) $balance) || abs((float) $balance) > 1000000000000) {
                throw ValidationException::withMessages(['supplier' => 'Respons saldo pemasok tidak valid.']);
            }

            return ['amount' => (int) floor((float) $balance), 'requested_at' => $startedAt];
        });

        return [...$snapshot['value'], 'observed_at' => $snapshot['observed_at']];
    }

    private function hanshopContext(SupplierConnection $connection): array
    {
        $store = $connection->store;

        return ['id_user' => $store->owner->hanshop_user_id, 'store_id' => $store->id,
            'access_until' => ($store->status === 'provisioning' ? now()->addMinutes(5) : $store->expires_at?->addDays($store->is_trial ? 0 : 7))?->toIso8601String()];
    }

    private function rows(SupplierConnection $connection, string $mode): array
    {
        if ($connection->kind === 'digiflazz' && $connection->store->sandbox) {
            app(StoreEnvironment::class)->credentials($connection);

            return $mode === 'pasca'
                ? [['sku' => 'pln', 'name' => 'PLN Pascabayar · Pengujian', 'kind' => 'postpaid', 'service' => 'Pascabayar', 'category' => 'PLN', 'cost' => 2500, 'available' => true, 'quantity' => null]]
                : [['sku' => 'xld10', 'name' => 'XL 10.000 · Pengujian', 'kind' => 'ppob', 'service' => 'Pulsa', 'category' => 'XL', 'cost' => 10000, 'available' => true, 'quantity' => null]];
        }
        if ($connection->kind === 'digiflazz') {
            $data = $this->digi($connection, 'price-list', $mode);
            if (! array_is_list($data) || count($data) > 20000) {
                throw ValidationException::withMessages(['supplier' => 'Respons katalog Digiflazz tidak valid.']);
            }

            return array_map(function ($item) use ($mode) {
                if (! is_array($item) || Validator::make($item, [
                    'buyer_sku_code' => 'required|string|max:150', 'product_name' => 'required|string|max:150',
                    'category' => 'required|string|max:100', 'brand' => 'required|string|max:100',
                    $mode === 'pasca' ? 'admin' : 'price' => 'required|integer|min:0|max:1000000000000',
                    'buyer_product_status' => 'required|boolean', 'seller_product_status' => 'required|boolean',
                    ...($mode === 'pasca' ? [] : ['unlimited_stock' => 'required|boolean', 'stock' => 'required|integer|min:0|max:2147483647']),
                ])->fails()) {
                    throw ValidationException::withMessages(['supplier' => 'Data produk Digiflazz tidak valid. Katalog sebelumnya dipertahankan.']);
                }
                $quantity = $mode === 'pasca' || $item['unlimited_stock'] ? null : (int) $item['stock'];

                return ['sku' => $item['buyer_sku_code'], 'name' => $item['product_name'], 'kind' => $mode === 'pasca' ? 'postpaid' : 'ppob',
                    'service' => $item['category'], 'category' => $item['brand'], 'cost' => (int) ($item[$mode === 'pasca' ? 'admin' : 'price']),
                    'available' => (bool) $item['buyer_product_status'] && (bool) $item['seller_product_status'] && ($quantity === null || $quantity > 0),
                    'quantity' => $quantity];
            }, $data);
        }
        $items = [];
        $cursor = null;
        $started = microtime(true);
        for ($page = 0; $page < 100; $page++) {
            $data = app(HanshopIdentity::class)->call('supplier/catalog', [...$this->hanshopContext($connection), 'cursor' => $cursor, 'limit' => 100]);
            if (($data['id_user'] ?? null) !== $connection->store->owner->hanshop_user_id || ! is_array($data['items'] ?? null) || ! array_is_list($data['items'])) {
                throw ValidationException::withMessages(['supplier' => 'Respons katalog Hanshop tidak valid.']);
            }
            foreach ($data['items'] as $item) {
                if (! is_array($item) || Validator::make($item, ['code' => 'required|string|max:150', 'name' => 'required|string|max:150',
                    'category_name' => 'required|string|max:100', 'service_name' => 'required|string|max:100', 'kind' => 'required|in:ppob,premium,postpaid',
                    'price' => 'required|integer|min:0|max:1000000000000', 'available' => 'required|boolean', 'quantity' => 'present|nullable|integer|min:0|max:2147483647'])->fails()) {
                    throw ValidationException::withMessages(['supplier' => 'Data produk Hanshop tidak valid. Katalog sebelumnya dipertahankan.']);
                }
                $items[] = ['sku' => $item['code'], 'name' => $item['name'], 'kind' => $item['kind'], 'service' => $item['service_name'],
                    'category' => $item['category_name'], 'cost' => (int) $item['price'], 'available' => (bool) $item['available'], 'quantity' => $item['quantity']];
            }
            $next = $data['next_cursor'] ?? null;
            if ($next === null) {
                return $items;
            }
            if (! is_string($next) || strlen($next) > 1024 || $next === $cursor || microtime(true) - $started > 40) {
                break;
            }
            $cursor = $next;
        }
        throw ValidationException::withMessages(['supplier' => 'Sinkronisasi katalog terlalu besar atau terputus. Katalog sebelumnya dipertahankan.']);
    }

    public function sync(SupplierConnection $connection, string $mode = 'prepaid'): int
    {
        CatalogAccess::operational($connection->store);
        abort_unless($connection->enabled && CatalogAccess::supports($connection->store, $connection->kind), 403);
        abort_unless(in_array($mode, ['prepaid', 'pasca'], true) && ($connection->kind === 'digiflazz' || $mode === 'prepaid'), 422);
        abort_if($connection->kind === 'otomax', 422, 'Katalog OtomaX diperbarui melalui impor Excel/CSV.');
        $account = $this->account($connection);
        try {
            $snapshot = $this->cache->remember($connection->kind, $account, 'catalog:'.$mode, fn () => $this->rows($connection, $mode));
            $rows = $snapshot['value'];
            $keys = array_column($rows, 'sku');
            if (count(array_unique($keys)) !== count($keys)) {
                throw ValidationException::withMessages(['supplier' => 'Pemasok mengirim kode produk duplikat.']);
            }
            DB::transaction(function () use ($connection, $snapshot, $rows, $mode, $account) {
                Store::whereKey($connection->store_id)->lockForUpdate()->firstOrFail();
                $locked = SupplierConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
                $locked->load('store.owner');
                CatalogAccess::operational($locked->store);
                abort_unless($locked->enabled && CatalogAccess::supports($locked->store, $locked->kind) && $this->account($locked) === $account, 409);
                SupplierProduct::where('connection_id', $locked->id)->where('mode', $mode)->update(['available' => false]);
                $values = array_map(fn ($item) => [...$item, 'id' => (string) Str::uuid7(), 'store_id' => $locked->store_id, 'connection_id' => $locked->id, 'mode' => $mode,
                    'observed_at' => Carbon::parse($snapshot['observed_at'])->format('Y-m-d H:i:s'), 'created_at' => now(), 'updated_at' => now()], $rows);
                foreach (array_chunk($values, 200) as $chunk) {
                    SupplierProduct::upsert($chunk, ['connection_id', 'mode', 'sku'], ['name', 'kind', 'service', 'category', 'cost', 'available', 'quantity', 'observed_at', 'updated_at']);
                }
                $locked->update(['synced_at' => $snapshot['observed_at'], 'catalog_modes' => array_values(array_unique([...($locked->catalog_modes ?? []), $mode])), 'last_error' => null]);
            });
            app(StoreCatalog::class)->reprice($connection->store);

            return count($rows);
        } catch (ValidationException $error) {
            $connection->update(['last_error' => Str::limit((string) collect($error->errors())->flatten()->first(), 250, '')]);
            throw $error;
        }
    }
}
