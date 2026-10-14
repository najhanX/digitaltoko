<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class SupplierCache
{
    public function remember(string $source, string $account, string $operation, Closure $read): array
    {
        $key = 'supplier:v1:'.$source.':'.hash_hmac('sha256', $account, (string) config('app.key')).':'.$operation;
        try {
            $cache = Cache::store(config('suppliers.cache_store', 'redis'));
            $cached = $cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }

            return $cache->lock($key.':lock', 60)->block(5, function () use ($cache, $key, $read) {
                if ($cache->has($key.':cooldown')) {
                    throw ValidationException::withMessages(['supplier' => 'Pemasok sedang membatasi permintaan. Coba kembali setelah beberapa menit.']);
                }
                if (is_array($cached = $cache->get($key))) {
                    return $cached;
                }
                try {
                    $value = $read();
                } catch (\Throwable $error) {
                    $cache->put($key.':cooldown', true, 120);
                    throw $error;
                }
                $snapshot = ['value' => $value, 'observed_at' => now()->toIso8601String()];
                $cache->put($key, $snapshot, max(60, min(180, (int) config('suppliers.cache_seconds', 120))));

                return $snapshot;
            });
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['supplier' => 'Cache atau koneksi pemasok belum tersedia. Periksa konfigurasi server lalu coba kembali.']);
        }
    }
}
