<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class SupplierCache
{
    public function lifetime(string $source, string $operation): int
    {
        return $source === 'digiflazz' && str_starts_with($operation, 'catalog:')
            ? 300
            : max(60, min(180, (int) config('suppliers.cache_seconds', 120)));
    }

    public function remember(string $source, string $account, string $operation, Closure $read): array
    {
        $key = 'supplier:v1:'.$source.':'.hash_hmac('sha256', $account, (string) config('app.key')).':'.$operation;
        $lifetime = $this->lifetime($source, $operation);
        try {
            $cache = Cache::store(config('suppliers.cache_store', 'redis'));
            $cached = $cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }

            return $cache->lock($key.':lock', 60)->block(5, function () use ($cache, $key, $read, $lifetime) {
                if (is_array($cached = $cache->get($key))) {
                    return $cached;
                }
                $failureKey = $key.':failure:v2';
                $failure = $cache->get($failureKey);
                if (is_array($failure)) {
                    $seconds = max(1, (int) $failure['until'] - now()->timestamp);
                    throw ValidationException::withMessages(['supplier' => $failure['message'].' Coba kembali dalam '.$seconds.' detik.']);
                }
                try {
                    $value = $read();
                } catch (\Throwable $error) {
                    $seconds = $error instanceof SupplierFailure && ! $error->rateLimited ? 15 : max(120, $lifetime);
                    $message = match (true) {
                        $error instanceof SupplierFailure => $error->getMessage(),
                        $error instanceof ValidationException => collect($error->errors())->flatten()->first() ?? 'Respons pemasok tidak valid.',
                        default => 'Koneksi pemasok gagal. Periksa layanan pemasok dan konfigurasi server.',
                    };
                    $cache->put($failureKey, ['message' => $message, 'until' => now()->timestamp + $seconds], $seconds);
                    throw ValidationException::withMessages(['supplier' => $message.' Coba kembali dalam '.$seconds.' detik.']);
                }
                $snapshot = ['value' => $value, 'observed_at' => now()->toIso8601String()];
                $cache->put($key, $snapshot, $lifetime);

                return $snapshot;
            });
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['supplier' => 'Cache atau koneksi pemasok belum tersedia. Periksa konfigurasi server lalu coba kembali.']);
        }
    }
}
