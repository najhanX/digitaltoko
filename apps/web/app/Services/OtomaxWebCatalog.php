<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class OtomaxWebCatalog
{
    public static function defaults(): array
    {
        return ['headers' => ['sku' => 'Kode', 'name' => 'Keterangan', 'cost' => 'Harga', 'status' => 'Status'], 'open_values' => 'Open,Aktif,Ready,Tersedia', 'closed_values' => 'Close,Closed,Gangguan,Nonaktif,Kosong,Habis', 'service' => 'Produk digital'];
    }

    public function target(string $url): array
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || strlen($url) > 2000) {
            $this->reject('Gunakan URL HTTPS lengkap dari daftar ALL produk supplier, tanpa username/password URL atau fragmen.');
        }
        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? 443;
        if ($port !== 443) {
            $this->reject('URL daftar harga harus memakai port HTTPS 443.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
        if (! $ips || count($ips) > 20) {
            $this->reject('Domain daftar harga tidak dapat dihubungi. Periksa URL supplier.');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) || (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && (int) explode('.', $ip)[0] >= 224) || (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && str_starts_with(strtolower($ip), 'ff'))) {
                $this->reject('URL daftar harga harus menuju alamat publik supplier, bukan jaringan lokal/internal.');
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public function fetch(array $config): array
    {
        $target = $this->target($config['url']);
        $key = 'otomax:url:v1:'.hash_hmac('sha256', json_encode([$config['url'], $config['profile']]), (string) config('app.key'));
        try {
            $cache = Cache::store(config('suppliers.cache_store', 'redis'));

            return $cache->lock($key.':lock', 45)->block(5, function () use ($cache, $key, $config, $target) {
                if ($encrypted = $cache->get($key)) {
                    return json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
                }
                if ($until = $cache->get($key.':cooldown')) {
                    $this->reject('Daftar harga sedang dalam masa tunggu. Coba kembali dalam '.max(1, $until - now()->timestamp).' detik.');
                }
                try {
                    $started = now()->startOfSecond()->toIso8601String();
                    $response = Http::withoutRedirecting()->accept('text/html')->connectTimeout(5)->timeout(30)
                        ->withOptions(['proxy' => '', 'curl' => [CURLOPT_RESOLVE => [$target['host'].':443:'.(str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'])]],
                            'progress' => static function ($total, $downloaded): void {
                                if ($total > 10485760 || $downloaded > 10485760) {
                                    throw new \RuntimeException('Catalog size limit');
                                }
                            },
                        ])->get($config['url']);
                    if (! $response->successful()) {
                        $this->reject('Daftar harga supplier ditolak atau belum tersedia (HTTP '.$response->status().'). Periksa URL dan izin akses supplier.');
                    }
                    $body = $response->body();
                    $rows = $this->parse($body, $config['profile']);
                    $snapshot = ['rows' => $rows, 'observed_at' => $started];
                    $cache->put($key, Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)), 300);

                    return $snapshot;
                } catch (\Throwable $error) {
                    $cache->put($key.':cooldown', now()->addMinutes(5)->timestamp, 300);
                    if ($error instanceof ValidationException) {
                        throw $error;
                    }
                    $this->reject('Daftar harga tidak dapat diunduh lengkap. Periksa koneksi supplier dan coba kembali setelah lima menit.');
                }
            });
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable) {
            $this->reject('Cache sinkronisasi belum tersedia. Periksa Redis server.');
        }
    }

    public function parse(string $html, array $profile): array
    {
        if ($html === '' || strlen($html) > 10485760 || ! mb_check_encoding($html, 'UTF-8') || preg_match('/<!ENTITY\b/i', $html)) {
            $this->reject('Daftar harga kosong, terlalu besar, atau format teks tidak didukung. Katalog lama dipertahankan.');
        }
        foreach (['table'] as $tag) {
            if (preg_match_all('/<'.$tag.'\b/i', $html) !== preg_match_all('/<\/'.$tag.'\s*>/i', $html)) {
                $this->reject('Tabel daftar harga terpotong. Katalog lama dipertahankan.');
            }
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                $this->reject('Struktur HTML daftar harga tidak valid.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);
        $tables = $xpath->query('//table[not(ancestor::table)]');
        $products = [];
        $recognizedTables = 0;
        $wanted = array_map(fn ($name) => $this->label($name), $profile['headers']);
        if (count(array_unique($wanted)) !== 4) {
            $this->reject('Nama empat kolom pembacaan harus berbeda.');
        }
        $open = $this->statuses($profile['open_values']);
        $closed = $this->statuses($profile['closed_values']);
        if (array_intersect($open, $closed)) {
            $this->reject('Status tersedia dan tidak tersedia tidak boleh sama.');
        }
        foreach ($tables as $table) {
            if ($xpath->query('.//table', $table)->length) {
                $this->reject('Tabel bersarang tidak didukung. Pilih format Webreport sederhana.');
            }
            $group = '';
            $columns = null;
            foreach ($xpath->query('.//tr', $table) as $tr) {
                $cells = $xpath->query('./td|./th', $tr);
                $values = [];
                foreach ($cells as $cell) {
                    $values[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent));
                }
                if (! array_filter($values, fn ($v) => $v !== '')) {
                    continue;
                }
                if (count($values) === 1 && (int) $cells->item(0)->getAttribute('colspan') > 1) {
                    $group = $values[0];

                    continue;
                }
                $labels = array_map(fn ($v) => $this->label($v), $values);
                if (! array_diff($wanted, $labels)) {
                    $columns = array_map(fn ($v) => array_search($v, $labels, true), $wanted);
                    if ($group !== '') {
                        $recognizedTables++;
                    }

                    continue;
                }
                if ($columns === null || count($values) <= max($columns)) {
                    $this->reject('Kolom tabel belum cocok atau ada baris tidak lengkap. Periksa format pembacaan.');
                }
                $sku = strtoupper($values[$columns['sku']]);
                $name = $values[$columns['name']];
                $cost = OtomaxParser::amount($values[$columns['cost']]);
                $status = mb_strtolower($values[$columns['status']]);
                if ($sku === '' || strlen($sku) > 150 || $name === '' || $group === '' || mb_strlen($group) > 100 || preg_match('/[\x00-\x20]/', $sku) || isset($products[$sku]) || ! in_array($status, [...$open, ...$closed], true)) {
                    $this->reject('Produk '.mb_substr($sku, 0, 80).' memiliki kode duplikat, harga/nama/kelompok tidak valid, atau status belum dipetakan. Periksa pratinjau dan format supplier.');
                }
                $validPrice = $cost !== null && $cost > 0 && $cost <= 1000000000000;
                $available = $validPrice && in_array($status, $open, true);
                $products[$sku] = ['sku' => $sku, 'name' => mb_substr($name, 0, 150), 'kind' => 'ppob', 'mode' => 'prepaid', 'service' => $profile['service'], 'category' => $group,
                    'cost' => $validPrice ? $cost : 0, 'available' => $available, 'quantity' => null, 'availability_reason' => ! $validPrice ? 'Harga supplier nol/tidak valid; produk tidak dapat dipesan.' : ($available ? null : 'Tidak tersedia menurut daftar harga supplier.')];
                if (count($products) > 20000) {
                    $this->reject('Daftar harga melebihi 20.000 produk.');
                }
            }
        }
        if (! $products && $recognizedTables === 0) {
            $this->reject('Tidak ada produk valid. Halaman kosong/login tidak dianggap sebagai penghapusan seluruh produk.');
        }

        return array_values($products);
    }

    private function statuses(string $values): array
    {
        return array_values(array_filter(array_map(fn ($v) => mb_strtolower(trim($v)), explode(',', $values))));
    }

    private function label(string $value): string
    {
        return mb_strtolower(preg_replace('/[\s_\-]+/u', '', $value));
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['catalog' => $message]);
    }
}
