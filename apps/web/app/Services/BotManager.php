<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class BotManager
{
    public function configured(): bool
    {
        $url = parse_url((string) config('services.bot_manager.url'));

        return is_array($url) && in_array($url['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true)
            && in_array($url['scheme'] ?? '', ['http', 'https'], true)
            && ! isset($url['user']) && ! isset($url['pass'])
            && strlen((string) config('services.bot_manager.token')) >= 32;
    }

    public function command(Store $store, string $action, array $data = []): void
    {
        if (! $this->configured()) {
            if ($store->status === 'provisioning') {
                app(Provisioning::class)->failure($store, 'Koneksi layanan bot platform belum dikonfigurasi. Administrator perlu memeriksa layanan.');
            }
            throw ValidationException::withMessages(['connection' => 'Administrator perlu mengatur koneksi layanan bot terlebih dahulu.']);
        }
        try {
            $response = Http::withToken(config('services.bot_manager.token'))->acceptJson()->timeout($action === 'message' ? 20 : 8)->connectTimeout(2)
                ->post(rtrim(config('services.bot_manager.url'), '/').'/stores/'.$store->id.'/'.$action, $data);
            $success = $response->successful() && $response->json('status') === 'SUCCESS';
        } catch (ConnectionException) {
            $success = false;
        }
        if (! $success) {
            if ($store->status === 'provisioning' && $action !== 'message') {
                app(Provisioning::class)->failure($store, 'Layanan bot belum menerima permintaan penyiapan. Administrator perlu memeriksa manager dan koneksi worker.');
            }
            if ($action === 'message') {
                $message = match (isset($response) ? $response->status() : null) {
                    404 => 'Bot manager masih memakai versi lama. Hentikan manager lalu jalankan npm run manager:dev kembali.',
                    409 => 'Worker WhatsApp toko belum aktif. Periksa koneksi WhatsApp pada panel toko.',
                    default => 'WhatsApp toko belum mengonfirmasi pengiriman OTP. Periksa koneksi toko lalu coba kembali.',
                };
                throw ValidationException::withMessages(['connection' => $message]);
            }
            throw ValidationException::withMessages(['connection' => 'Layanan bot belum dapat menerima permintaan. Pastikan bot manager berjalan, lalu coba kembali.']);
        }
    }
}
