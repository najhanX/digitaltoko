<?php

namespace App\Services;

use App\Models\CustomerOrder;
use App\Models\OrderAttempt;
use App\Models\Store;
use App\Models\SupplierConnection;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\RequestInterface;

class SupplierSimulator
{
    public function enabled(): bool
    {
        return (bool) config('suppliers.simulator.enabled') && app()->environment('local', 'testing');
    }

    public function assertIsolated(): void
    {
        $database = config('database.connections.sqlite.database');
        $expected = storage_path('app/private/supplier-lab.sqlite');
        $validDatabase = config('database.default') === 'sqlite'
            && (($database === ':memory:' && app()->environment('testing')) || str_replace('\\', '/', (string) $database) === str_replace('\\', '/', $expected));
        if (! $this->enabled() || ! $validDatabase) {
            throw new \RuntimeException('Supplier Lab hanya boleh aktif pada local/testing dengan database SQLite supplier-lab terpisah.');
        }
    }

    public function target(string $url): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $this->assertIsolated();
        foreach (config('suppliers.simulator.origins') as $slot => $origin) {
            $parts = parse_url($origin);
            if (($parts['scheme'] ?? '') !== 'http' || ($parts['host'] ?? '') !== '127.0.0.1' || ! in_array($parts['port'] ?? null, [4101, 4102, 4103], true)) {
                throw new \RuntimeException('Alamat Supplier Lab harus loopback dengan port tetap 4101–4103.');
            }
            if ($url === $origin || $url === $origin.'/harga.js.php') {
                return ['url' => $origin, 'host' => '127.0.0.1', 'port' => $parts['port'], 'ip' => '127.0.0.1', 'slot' => $slot];
            }
        }

        return null;
    }

    public function connection(SupplierConnection $connection, ?array $snapshot = null): bool
    {
        $settings = $snapshot['settings'] ?? $connection->settings;
        $target = $this->target((string) ($settings['base_url'] ?? ''));
        if (! $target) {
            return false;
        }
        $credentials = $snapshot['credentials'] ?? $connection->credentials;
        $index = array_search($target['slot'], ['a', 'b', 'c'], true) + 1;
        if ($connection->kind !== 'otomax' || ! $connection->store->sandbox || ! in_array($connection->store->slug, config('suppliers.simulator.slugs'), true)
            || ($credentials['member_id'] ?? '') !== 'SIM00'.$index || ($credentials['pin'] ?? '') !== '1234' || ($credentials['password'] ?? '') !== 'simulator-only') {
            throw ValidationException::withMessages(['supplier' => 'Koneksi simulator hanya untuk toko lab sandbox dan kredensial sintetis yang ditetapkan.']);
        }

        return true;
    }

    public function store(Store $store): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $this->assertIsolated();

        return $store->sandbox && in_array($store->slug, config('suppliers.simulator.slugs'), true);
    }

    public function order(CustomerOrder $order, OrderAttempt $attempt): bool
    {
        if (! $order->is_sandbox || $attempt->source !== 'otomax' || ! $this->enabled()) {
            return false;
        }
        $connection = SupplierConnection::where('store_id', $order->store_id)->find($attempt->request_snapshot['connection_id'] ?? null);

        return $connection && $this->connection($connection, $attempt->request_snapshot['otomax'] ?? null);
    }

    public function secureRuntime(): void
    {
        $this->assertIsolated();
        config(['cache.stores.file.path' => storage_path('framework/cache/supplier-lab'), 'cache.stores.file.lock_path' => storage_path('framework/cache/supplier-lab'),
            'session.files' => storage_path('framework/sessions/supplier-lab'), 'session.cookie' => 'supplier_lab_session', 'mail.default' => 'array', 'queue.default' => 'sync', 'suppliers.cache_store' => 'file', 'inertia.ssr.enabled' => false]);
        app(Vite::class)->useHotFile(storage_path('framework/supplier-lab.hot'));
        Http::globalRequestMiddleware($this->guardRequest(...));
    }

    public function guardRequest(RequestInterface $request): RequestInterface
    {
        $this->assertIsolated();
        $uri = $request->getUri();
        $origin = $uri->getScheme().'://'.$uri->getHost().':'.($uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80));
        if ($origin === 'https://api.digiflazz.com:443' && $uri->getPath() === '/v1/transaction' && $this->developmentRequest($request)) {
            return $request;
        }
        if (! in_array($origin, [...array_values(config('suppliers.simulator.origins')), 'http://127.0.0.1:4100'], true)) {
            throw new \RuntimeException('Supplier Lab memblokir koneksi HTTP di luar simulator lokal.');
        }

        return $request;
    }

    private function developmentRequest(RequestInterface $request): bool
    {
        if (! config('suppliers.simulator.digiflazz_development') || $request->getMethod() !== 'POST' || $request->getUri()->getQuery() !== '') {
            return false;
        }
        $data = json_decode((string) $request->getBody(), true);
        if (! is_array($data) || ($data['testing'] ?? null) !== true || ($data['buyer_sku_code'] ?? '') !== 'xld10'
            || ! in_array($data['customer_no'] ?? '', ['087800001230', '087800001232', '087800001233', '087800001234'], true)
            || isset($data['commands']) || ! is_string($data['ref_id'] ?? null) || ! is_string($data['sign'] ?? null)
            || (isset($data['cb_url']) && $data['cb_url'] !== $this->developmentCallbackUrl())) {
            return false;
        }
        $attempt = OrderAttempt::with('order.store')->where('source', 'digiflazz')->where('reference', $data['ref_id'])->first();
        if (! $attempt || ! $attempt->order->is_sandbox || ! $this->store($attempt->order->store)
            || $attempt->order->destination !== $data['customer_no'] || ($attempt->request_snapshot['sku'] ?? '') !== 'xld10') {
            return false;
        }
        $connection = SupplierConnection::where('store_id', $attempt->order->store_id)->where('kind', 'digiflazz')->where('enabled', true)->find($attempt->request_snapshot['connection_id'] ?? null);
        $credentials = $connection?->credentials ?? [];
        $key = $credentials['development_key'] ?? '';

        return $key !== '' && ($data['username'] ?? '') === ($credentials['username'] ?? null)
            && hash_equals(md5($credentials['username'].$key.$data['ref_id']), $data['sign']);
    }

    public function developmentCallbackUrl(): ?string
    {
        $url = config('suppliers.simulator.digiflazz_callback_url');
        if (! filled($url)) {
            return null;
        }
        $p = parse_url($url);
        if (! is_array($p) || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || ($p['port'] ?? 443) !== 443
            || ($p['path'] ?? '') !== '/api/commerce/digiflazz' || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            throw new \RuntimeException('URL callback lab harus HTTPS dan path /api/commerce/digiflazz tanpa query atau kredensial.');
        }

        return $url;
    }
}
