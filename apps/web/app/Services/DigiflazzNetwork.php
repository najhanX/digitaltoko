<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DigiflazzNetwork
{
    public function whitelistMessage(bool $testing, mixed $supplierMessage = null): string
    {
        $message = 'IP server belum terdaftar pada whitelist Digiflazz mode '.($testing ? 'Development' : 'Production').' (RC 45).';
        if (is_string($supplierMessage)) {
            preg_match_all('/(?<![0-9.])(?:[0-9]{1,3}\.){3}[0-9]{1,3}(?![0-9.])/', $supplierMessage, $matches);
            foreach ($matches[0] as $candidate) {
                if ($this->validIp($candidate)) {
                    return $message.' Tambahkan IP '.$candidate.' ke whitelist pada mode tersebut.';
                }
            }
        }
        $configured = config('suppliers.digiflazz_outbound_ip');
        if ($this->validIp($configured)) {
            return $message.' Tambahkan IP '.$configured.' ke whitelist pada mode tersebut.';
        }
        try {
            $ip = Cache::remember('digiflazz:outbound-ip:v1:'.hash('sha256', gethostname() ?: 'server'), 120, function () {
                try {
                    $response = Http::withoutRedirecting()->connectTimeout(2)->timeout(3)->get('https://api.ipify.org');
                    $ip = $response->successful() ? trim($response->body()) : null;

                    return $this->validIp($ip) ? $ip : '';
                } catch (\Throwable) {
                    return '';
                }
            });
        } catch (\Throwable) {
            $ip = '';
        }

        return $this->validIp($ip)
            ? $message.' IP publik keluar server terdeteksi: '.$ip.'. Tambahkan IP ini ke whitelist; jika memakai proxy atau jalur jaringan khusus, periksa IP yang ditolak di log Digiflazz.'
            : $message.' IP publik keluar server belum dapat dideteksi. Periksa IP yang ditolak di log Digiflazz lalu tambahkan ke whitelist pada mode tersebut.';
    }

    private function validIp(mixed $ip): bool
    {
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
