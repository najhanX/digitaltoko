<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Support\PlatformHost;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StoreDomains
{
    public function hostname(string $value): string
    {
        $host = strtolower(rtrim(trim($value), '.'));
        $valid = strlen($host) <= 253 && str_contains($host, '.')
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            && ! filter_var($host, FILTER_VALIDATE_IP) && preg_match('/\.[a-z]{2,63}$/D', $host)
            && ! preg_match('/\.(localhost|local|test|invalid|example)$/D', $host);
        foreach (app(PlatformHost::class)->domains() as $platform) {
            if ($host === $platform || str_ends_with($host, '.'.$platform)) {
                $valid = false;
            }
        }
        if (! $valid) {
            throw ValidationException::withMessages(['hostname' => 'Masukkan domain publik milik Anda, tanpa https://, port, path, atau wildcard. Domain platform tidak dapat diklaim.']);
        }

        return $host;
    }

    public function target(Store $store): string
    {
        return strtolower(rtrim((string) (config('domains.cname_target') ?: $store->slug.'.'.app(PlatformHost::class)->domain()), '.'));
    }

    public function details(Store $store): array
    {
        $domain = $store->domain;
        $target = $this->target($store);

        return ['domain' => $domain?->only(['id', 'hostname', 'status', 'verified_at', 'dns_verified_at', 'tls_verified_at', 'last_checked_at', 'last_error', 'verification_expires_at']),
            'txtName' => $domain ? '_digitaltoko-domain.'.$domain->hostname : null,
            'txtValue' => $domain?->verification_token ? 'digitaltoko='.$domain->verification_token : null,
            'target' => $target, 'originIps' => array_values(array_filter(config('domains.origin_ips', []), DomainDns::publicIp(...))),
            'defaultUrl' => $this->defaultUrl($store)];
    }

    public function defaultUrl(Store $store): string
    {
        $url = parse_url((string) config('app.url'));

        return ($url['scheme'] ?? 'https').'://'.$store->slug.'.'.app(PlatformHost::class)->domain().(isset($url['port']) ? ':'.$url['port'] : '');
    }

    public function save(Store $store, string $hostname): void
    {
        $hostname = $this->hostname($hostname);
        try {
            DB::transaction(function () use ($store, $hostname) {
                Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
                $taken = StoreDomain::where('hostname', $hostname)->where('store_id', '!=', $store->id)->lockForUpdate()->first();
                if ($taken && ! $taken->dns_verified_at && ! $taken->verified_at && $taken->verification_expires_at?->isPast()) {
                    $taken->delete();
                    $taken = null;
                }
                if ($taken) {
                    throw ValidationException::withMessages(['hostname' => 'Domain sudah terdaftar pada toko lain. Hubungi dukungan jika domain ini milik Anda.']);
                }
                $domain = StoreDomain::where('store_id', $store->id)->lockForUpdate()->first();
                if ($domain?->hostname === $hostname && $domain->verification_token && ! $domain->verification_expires_at?->isPast()) {
                    return;
                }
                StoreDomain::updateOrCreate(['store_id' => $store->id], ['hostname' => $hostname, 'status' => 'pending_verification',
                    'verification_token' => Str::random(48), 'verification_expires_at' => now()->addDays(7),
                    'verified_at' => null, 'dns_verified_at' => null, 'tls_verified_at' => null, 'last_checked_at' => null, 'last_error' => null]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['hostname' => 'Domain baru saja didaftarkan. Muat ulang dan periksa kembali.']);
        }
    }

    public function verify(StoreDomain $domain): void
    {
        $host = $domain->hostname;
        $token = $domain->verification_token;
        if (! $token || $domain->verification_expires_at?->isPast()) {
            throw ValidationException::withMessages(['domain' => 'Kode verifikasi belum ada atau kedaluwarsa. Simpan domain kembali untuk mendapatkan kode baru.']);
        }
        $dns = app(DomainDns::class);
        $failure = null;
        $dnsReady = false;
        $tlsReady = false;
        try {
            $values = array_map(fn ($record) => isset($record['entries']) ? implode('', $record['entries']) : ($record['txt'] ?? ''), $dns->records('_digitaltoko-domain.'.$host, DNS_TXT));
            if (! in_array('digitaltoko='.$token, $values, true)) {
                throw new RuntimeException('Record TXT kepemilikan belum cocok. Periksa nama record dan nilainya, lalu tunggu propagasi DNS.');
            }
            $ips = $dns->addresses($host);
            if (! $ips || count(array_filter($ips, DomainDns::publicIp(...))) !== count($ips)) {
                throw new RuntimeException('Domain harus mengarah ke alamat IP publik layanan toko. Periksa DNS dan hapus record yang bertentangan.');
            }
            $target = $this->target($domain->store);
            $expected = config('domains.origin_ips') ?: $dns->addresses($target);
            if (count(array_diff($ips, $expected)) !== 0) {
                throw new RuntimeException('Arah DNS belum sesuai. Gunakan CNAME ke target yang ditampilkan, atau A/AAAA sesuai alamat layanan. Nonaktifkan proxy sementara saat verifikasi.');
            }
            $dnsReady = true;
            $this->record($domain, $host, $token, ['status' => $domain->status === 'active' ? 'active' : 'pending_tls', 'dns_verified_at' => now(), 'verification_expires_at' => null, 'last_error' => null]);
            if (! extension_loaded('curl')) {
                throw new RuntimeException('DNS sudah cocok. Administrator perlu mengaktifkan cURL untuk pemeriksaan HTTPS.');
            }
            $ip = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];
            $response = Http::withOptions(['allow_redirects' => false, 'verify' => true, 'proxy' => '', 'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$ip]]])
                ->connectTimeout(5)->timeout(30)->acceptJson()->get('https://'.$host.'/api/domains/proof');
            $tlsReady = $response->ok() && $response->json('hostname') === $host && $response->json('token') === $token;
            if (! $tlsReady) {
                $failure = 'DNS sudah cocok, tetapi HTTPS belum menuju toko ini. Tunggu penyiapan sertifikat atau hubungi dukungan, lalu periksa kembali.';
            }
        } catch (ConnectionException) {
            $failure = 'DNS sudah cocok, tetapi HTTPS belum siap. Tunggu penyiapan sertifikat lalu periksa kembali. Jika berlanjut, hubungi dukungan.';
        } catch (RuntimeException $exception) {
            $failure = $exception->getMessage();
        }
        $this->record($domain, $host, $token, ['status' => $tlsReady ? 'active' : ($dnsReady ? 'pending_tls' : 'pending_verification'),
            'verified_at' => $tlsReady ? now() : null, 'tls_verified_at' => $tlsReady ? now() : null,
            'dns_verified_at' => $dnsReady ? now() : null, 'last_error' => $failure, 'last_checked_at' => now()]);
    }

    private function record(StoreDomain $domain, string $host, string $token, array $changes): void
    {
        DB::transaction(function () use ($domain, $host, $token, $changes) {
            $locked = StoreDomain::whereKey($domain->id)->lockForUpdate()->first();
            if (! $locked || $locked->hostname !== $host || $locked->verification_token !== $token) {
                throw ValidationException::withMessages(['domain' => 'Pengaturan domain berubah saat diperiksa. Muat ulang lalu coba kembali.']);
            }
            $locked->update($changes);
        });
    }
}
