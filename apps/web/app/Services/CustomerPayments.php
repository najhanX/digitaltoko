<?php

namespace App\Services;

use App\Models\CommerceReceipt;
use App\Models\CustomerOrder;
use App\Models\Store;
use App\Models\StorePaymentSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CustomerPayments
{
    private function base(CustomerOrder $order): string
    {
        app(CustomerCommerce::class)->ensure(! $order->is_sandbox || ($order->snapshot['sandbox'] ?? false), 'Pesanan pengujian hanya boleh memakai Midtrans Sandbox.');

        return ($order->snapshot['sandbox'] ?? true) ? 'https://api.sandbox.midtrans.com/v2/' : 'https://api.midtrans.com/v2/';
    }

    private function client(CustomerOrder $order): PendingRequest
    {
        return Http::withBasicAuth($order->payment_credentials['server_key'], '')->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20);
    }

    public function canSimulate(CustomerOrder $order): bool
    {
        return $order->is_sandbox && ($order->gateway === 'sandbox' || ($order->gateway === 'midtrans' && ($order->snapshot['sandbox'] ?? false)))
            && $order->payment_status === 'unpaid' && $order->status === 'awaiting_payment' && $order->expires_at->isFuture();
    }

    public function simulate(CustomerOrder $order): void
    {
        DB::transaction(function () use ($order) {
            StoreRetention::guard(Store::whereKey($order->store_id)->lockForUpdate()->firstOrFail());
            $locked = CustomerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->is_sandbox && ($locked->gateway === 'sandbox' || ($locked->gateway === 'midtrans' && ($locked->snapshot['sandbox'] ?? false))), 403);
            if ($locked->payment_status === 'paid') {
                return;
            }
            app(CustomerCommerce::class)->ensure($this->canSimulate($locked), 'Invoice pengujian sudah tidak aktif. Buat pesanan uji baru.');
            $locked->update(['snapshot' => [...$locked->snapshot, 'payment_simulation' => ['method' => 'local', 'at' => now()->toIso8601String()]]]);
            app(CustomerCommerce::class)->paid($locked, 'sandbox:'.$locked->id, $locked->total, now());
        }, 3);
    }

    public function start(CustomerOrder $order): void
    {
        $order->refresh();
        if ($order->payment_status !== 'unpaid' || $order->status !== 'awaiting_payment' || $order->expires_at->isPast()) {
            return;
        }
        Cache::lock('payment-create:'.$order->id, 60)->get(function () use ($order) {
            $order->refresh();
            if ($order->payment_status !== 'unpaid' || $order->status !== 'awaiting_payment' || $order->expires_at->isPast()) {
                return;
            }
            if ($order->gateway === 'custom') {
                if (! $order->qr_image) {
                    $payload = app(CustomQris::class)->amount($order->payment_credentials['qris_payload'], $order->total);
                    $image = app(CustomQris::class)->image($payload);
                    $order->update(['qr_payload' => $payload, 'qr_image' => $image]);
                }

                return;
            }
            if ($order->gateway !== 'midtrans') {
                return;
            }
            if ($order->provider_id) {
                $this->repairQr($order);

                return;
            }
            $response = $this->client($order)->post($this->base($order).'charge', ['payment_type' => 'qris', 'transaction_details' => ['order_id' => $order->number, 'gross_amount' => $order->total],
                'customer_details' => ['phone' => $order->phone, ...($order->email ? ['email' => $order->email] : [])],
                'custom_expiry' => ['order_time' => $order->created_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i:s O'), 'expiry_duration' => max(1, (int) ceil($order->created_at->diffInSeconds($order->expires_at) / 60)), 'unit' => 'minute']]);
            if (! $response->successful() || ! in_array((string) $response->json('status_code'), ['200', '201'], true)) {
                $this->refresh($order);

                return;
            }
            $data = $response->json();
            $this->verifyAmount($order, $data);
            $id = $data['transaction_id'] ?? null;
            app(CustomerCommerce::class)->ensure(is_string($id) && preg_match('/^[a-zA-Z0-9-]{1,100}$/', $id) === 1, 'Respons pembayaran Midtrans tidak valid.');
            $order->update(['provider_id' => $id, 'qr_image' => $this->qrUrl($order, $data, $id)]);
            $this->repairQr($order);
        });
    }

    private function qrUrl(CustomerOrder $order, array $data, string $id): string
    {
        foreach (['generate-qr-code', 'generate-qr-code-v2'] as $name) {
            foreach ($data['actions'] ?? [] as $action) {
                if (is_array($action) && ($action['name'] ?? '') === $name && ($action['method'] ?? '') === 'GET' && $this->validQrUrl($order, $action['url'] ?? null)) {
                    return $action['url'];
                }
            }
        }

        return $this->base($order).'qris/'.rawurlencode($id).'/qr-code';
    }

    private function validQrUrl(CustomerOrder $order, mixed $url): bool
    {
        if (! is_string($url)) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === parse_url($this->base($order), PHP_URL_HOST)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && preg_match('#^/v[24]/qris/[a-zA-Z0-9_-]+/qr-code$#', $parts['path'] ?? '') === 1;
    }

    public function repairQr(CustomerOrder $order): void
    {
        if ($order->gateway !== 'midtrans' || ! $order->provider_id || $order->payment_status !== 'unpaid' || $order->status !== 'awaiting_payment' || $order->expires_at->isPast() || str_starts_with($order->qr_image ?? '', 'data:image/png;base64,')) {
            return;
        }
        $url = $this->validQrUrl($order, $order->qr_image) ? $order->qr_image : $this->base($order).'qris/'.rawurlencode($order->provider_id).'/qr-code';
        app(CustomerCommerce::class)->ensure($this->validQrUrl($order, $url), 'URL QRIS Midtrans tidak valid.');
        $response = $this->client($order)->get($url);
        $png = $response->body();
        app(CustomerCommerce::class)->ensure($response->successful() && strlen($png) <= 45000 && str_starts_with($png, "\x89PNG\r\n\x1a\n") && @getimagesizefromstring($png) !== false, 'Gambar QRIS belum dapat dimuat. Coba Periksa / siapkan pembayaran.');
        $order->update(['qr_image' => 'data:image/png;base64,'.base64_encode($png)]);
    }

    private function verifyAmount(CustomerOrder $order, array $data): void
    {
        app(CustomerCommerce::class)->ensure(($data['order_id'] ?? '') === $order->number && preg_match('/^[0-9]+(?:\.00)?$/', (string) ($data['gross_amount'] ?? '')) && (int) $data['gross_amount'] === $order->total && ($data['currency'] ?? 'IDR') === 'IDR', 'Nominal atau referensi Midtrans tidak sesuai.');
    }

    public function refreshForReceipt(CustomerOrder $order): bool
    {
        if ($order->gateway !== 'midtrans' || $order->payment_status === 'paid' || $order->status !== 'awaiting_payment') {
            return true;
        }
        $key = 'receipt-payment-check:'.$order->id;
        $cached = Cache::get($key);
        if (is_bool($cached)) {
            return $cached;
        }
        $lock = Cache::lock($key.':lock', 30);
        if (! $lock->get()) {
            return true;
        }
        try {
            $cached = Cache::get($key);
            if (is_bool($cached)) {
                return $cached;
            }
            $expiresAt = now()->addSeconds(10);
            try {
                $this->refresh($order->fresh());
                $checked = true;
            } catch (\Throwable $exception) {
                report($exception);
                $checked = false;
            }
            Cache::put($key, $checked, $expiresAt);

            return $checked;
        } finally {
            $lock->release();
        }
    }

    public function refresh(CustomerOrder $order): ?array
    {
        if ($order->gateway !== 'midtrans' || $order->payment_status === 'paid') {
            return null;
        }

        return $this->applyStatus($order, $this->statusRequest($order));
    }

    private function statusRequest(CustomerOrder $order, ?string $key = null): Response
    {
        $client = $key === null ? $this->client($order) : Http::withBasicAuth($key, '')->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20);

        return $client->get($this->base($order).rawurlencode($order->number).'/status');
    }

    private function authenticationDenied(Response $response): bool
    {
        return in_array($response->status(), [401, 403], true) || (string) $response->json('status_code') === '401';
    }

    private function notFound(Response $response): bool
    {
        return ($response->successful() || $response->status() === 404) && (string) $response->json('status_code') === '404';
    }

    private function applyStatus(CustomerOrder $order, Response $response): ?array
    {
        app(CustomerCommerce::class)->ensure(! $this->authenticationDenied($response), 'Server key yang tersimpan pada invoice ini ditolak Midtrans (401/403). Periksa key dan mode Sandbox/Produksi. Invoice lama tetap memakai key saat dibuat.');
        if ($this->notFound($response)) {
            return null;
        }
        app(CustomerCommerce::class)->ensure($response->successful(), 'Status Midtrans belum dapat diperiksa.');
        $data = $response->json();
        $this->verifyAmount($order, $data);
        $status = $data['transaction_status'] ?? '';
        if (in_array($status, ['settlement', 'capture'], true) && ($data['fraud_status'] ?? 'accept') === 'accept') {
            app(CustomerCommerce::class)->ensure((string) ($data['status_code'] ?? '') === '200', 'Status pembayaran Midtrans tidak valid.');
            $paidAt = Carbon::parse($data['settlement_time'] ?? $data['transaction_time'], 'Asia/Jakarta')->utc();
            app(CustomerCommerce::class)->paid($order, 'midtrans:'.($data['transaction_id'] ?? $order->number), $order->total, $paidAt);
        } elseif (in_array($status, ['expire', 'cancel', 'deny', 'failure'], true)) {
            DB::transaction(function () use ($order) {
                StoreRetention::guard(Store::whereKey($order->store_id)->lockForUpdate()->firstOrFail());
                $locked = CustomerOrder::whereKey($order->id)->lockForUpdate()->first();
                if ($locked->payment_status === 'unpaid' && $locked->status === 'awaiting_payment') {
                    $locked->update(['status' => 'expired', 'message' => 'Pembayaran tidak diselesaikan.']);
                    app(CustomerCommerce::class)->notice($locked, 'expired');
                }
            });
        } elseif (is_string($data['transaction_id'] ?? null)) {
            $id = $data['transaction_id'];
            app(CustomerCommerce::class)->ensure(preg_match('/^[a-zA-Z0-9-]{1,100}$/', $id) === 1, 'Referensi pembayaran tidak valid.');
            if (! $order->provider_id) {
                $order->update(['provider_id' => $id, 'qr_image' => $this->qrUrl($order, $data, $id)]);
            }
        }

        return $data;
    }

    public function notification(CustomerOrder $order, array $data): void
    {
        app(CustomerCommerce::class)->ensure($order->gateway === 'midtrans', 'Gateway tidak sesuai.');
        $key = $order->payment_credentials['server_key'];
        $expected = hash('sha512', ($data['order_id'] ?? '').($data['status_code'] ?? '').($data['gross_amount'] ?? '').$key);
        abort_unless(is_string($data['signature_key'] ?? null) && hash_equals($expected, $data['signature_key']), 401);
        $this->verifyAmount($order, $data);
        $this->refresh($order);
    }

    private function cancellationConfirmed(CustomerOrder $order, array $data): bool
    {
        if (! in_array($data['transaction_status'] ?? '', ['cancel', 'expire'], true)) {
            return false;
        }
        $this->verifyAmount($order, $data);

        return true;
    }

    private function cancellationStatus(CustomerOrder $order, ?int $ownerId): ?array
    {
        $response = $this->statusRequest($order);
        if ($this->authenticationDenied($response) && $ownerId !== null
            && ($order->snapshot['sandbox'] ?? false) && ! $order->provider_id && ! $order->qr_image && ! $order->qr_payload
            && ! $order->attempts()->whereNotNull('last_checked_at')->exists()) {
            $setting = StorePaymentSetting::find($order->store_id);
            $currentKey = $setting?->credentials['server_key'] ?? '';
            $originalKey = $order->payment_credentials['server_key'] ?? '';
            if ($setting?->gateway === 'midtrans' && $setting->sandbox && filled($currentKey) && ! hash_equals($originalKey, $currentKey)) {
                $probe = $this->statusRequest($order, $currentKey);
                if (! $this->authenticationDenied($probe) && $this->notFound($probe)) {
                    return ['sandbox_uncreated' => true];
                }
            }
        }

        return $this->applyStatus($order, $response);
    }

    public function cancel(CustomerOrder $order, ?int $ownerId = null): void
    {
        Cache::lock('payment-create:'.$order->id, 60)->block(10, function () use ($order, $ownerId) {
            $order->refresh();
            $commerce = app(CustomerCommerce::class);
            $commerce->ensure($order->payment_status === 'unpaid' && in_array($order->status, ['awaiting_payment', 'expired'], true), 'Pesanan yang sudah dibayar atau diproses tidak dapat dibatalkan.');
            $recovery = false;
            if ($order->gateway === 'midtrans') {
                $remote = $this->cancellationStatus($order, $ownerId);
                $recovery = (bool) ($remote['sandbox_uncreated'] ?? false);
                $order->refresh();
                $commerce->ensure($order->payment_status === 'unpaid', 'Pembayaran sudah diterima. Pesanan tidak dapat dibatalkan.');
                $confirmed = $remote && $this->cancellationConfirmed($order, $remote);
                if (! $confirmed && $order->provider_id) {
                    $commerce->ensure(($remote['transaction_status'] ?? '') === 'pending', 'Status pembayaran telah berubah. Periksa kembali pesanan.');
                    $response = $this->client($order)->post($this->base($order).rawurlencode($order->number).'/cancel');
                    $confirmed = $response->successful() && $this->cancellationConfirmed($order, $response->json() ?? []);
                    if (! $confirmed) {
                        $remote = $this->refresh($order);
                        $order->refresh();
                        $commerce->ensure($order->payment_status === 'unpaid', 'Pembayaran sudah diterima. Pesanan tidak dapat dibatalkan.');
                        $confirmed = $remote && $this->cancellationConfirmed($order, $remote);
                        if (! $confirmed && ($remote['transaction_status'] ?? '') === 'pending') {
                            $response = $this->client($order)->post($this->base($order).rawurlencode($order->number).'/expire');
                            $confirmed = $response->successful() && $this->cancellationConfirmed($order, $response->json() ?? []);
                            if (! $confirmed) {
                                $remote = $this->refresh($order);
                                $confirmed = $remote && $this->cancellationConfirmed($order, $remote);
                            }
                        }
                        $commerce->ensure((bool) $confirmed, 'Midtrans belum dapat menonaktifkan invoice (kode '.(string) ($response->json('status_code') ?? $response->status()).'). Pesanan tetap menunggu pembayaran; coba kembali beberapa saat lagi.');
                    }
                }
            }
            DB::transaction(function () use ($order, $commerce, $ownerId, $recovery) {
                StoreRetention::guard(Store::whereKey($order->store_id)->lockForUpdate()->firstOrFail());
                $locked = CustomerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $commerce->ensure($locked->payment_status === 'unpaid' && in_array($locked->status, ['awaiting_payment', 'expired'], true), 'Pesanan sudah dibayar atau statusnya berubah. Pembatalan dihentikan.');
                $locked->update(['status' => 'expired', 'completed_at' => now(), 'qr_image' => null, 'snapshot' => [...$locked->snapshot, 'cancelled_by_customer' => $ownerId === null, 'cancelled_by_owner' => $ownerId, ...($recovery ? ['cancellation_recovery' => ['reason' => 'sandbox_uncreated_stale_key', 'at' => now()->toIso8601String()]] : [])], 'message' => $ownerId === null ? 'Pesanan dibatalkan oleh pelanggan. Jangan membayar invoice ini.' : 'Pesanan dibatalkan oleh owner toko. Jangan membayar invoice ini.']);
                $commerce->notice($locked, 'expired', 'cancelled');
            });
        });
    }

    public function custom(Store $store, array $data, ?array $context = null): void
    {
        $setting = app(CustomerCommerce::class)->settings($store);
        $credentials = $context ?? $setting->credentials ?? [];
        app(CustomerCommerce::class)->ensure($setting->custom_licensed_at !== null, 'Lisensi QRIS custom tidak aktif.');
        $package = $credentials['application_package'] ?? 'id.dana';
        $sourceMatches = filled($package)
            ? ($data['application_package'] ?? '') === $package
            : filled($credentials['application_name'] ?? '') && mb_strtolower(trim($data['app_name'] ?? '')) === mb_strtolower(trim($credentials['application_name']));
        abort_unless($sourceMatches, 422, 'Aplikasi sumber notifikasi tidak cocok dengan pengaturan toko.');
        $text = ($data['title'] ?? '').' '.($data['text'] ?? '');
        $current = $setting->credentials ?? [];
        $sameSource = ($current['application_package'] ?? 'id.dana') === ($credentials['application_package'] ?? 'id.dana')
            && mb_strtolower(trim($current['application_name'] ?? '')) === mb_strtolower(trim($credentials['application_name'] ?? ''));
        $parserConfig = ($sameSource ? $current['notification_parser'] ?? null : null) ?? ($credentials['notification_parser'] ?? null);
        if ($parserConfig !== null) {
            $parsed = app(QrisNotificationParser::class)->parse($parserConfig, $data);
            app(CustomerCommerce::class)->ensure($parsed['matched'], $parsed['reason']);
            $amount = $parsed['amount'];
        } else {
            $incoming = preg_match('/(?:pembayaran|uang|saldo|transfer).*(?:diterima|masuk)|(?:terima|menerima).*(?:pembayaran|uang|transfer)/iu', $text) === 1;
            $danaBusinessReceipt = ($data['application_package'] ?? '') === 'id.dana'
                && preg_match('/\ARp\s*[0-9][0-9.]*(?:,[0-9]{2})?\s+diterima\s+DANA\s+Bisnis\.?\s*\z/iu', trim($data['text'] ?? '')) === 1;
            app(CustomerCommerce::class)->ensure(($incoming || $danaBusinessReceipt) && ! preg_match('/(?:gagal|dibatalkan|keluar|berhasil dikirim)/iu', $text), 'Notifikasi bukan pembayaran masuk.');
            preg_match_all('/Rp\s*([0-9][0-9.]*(?:,[0-9]{2})?)/iu', $text, $matches);
            $amounts = array_unique(array_map(fn ($s) => (int) str_replace('.', '', explode(',', $s)[0]), $matches[1]));
            app(CustomerCommerce::class)->ensure(count($amounts) === 1 && reset($amounts) > 0, 'Nominal notifikasi tidak jelas.');
            $amount = (int) reset($amounts);
        }
        $at = Carbon::parse($data['occurred_at'])->utc();
        app(CustomerCommerce::class)->ensure($at->lte(now()->addSeconds(30)) && $at->gte(now()->subDay()), 'Waktu notifikasi di luar batas pemeriksaan.');
        $merchant = hash('sha256', app(CustomQris::class)->identity($credentials['qris_payload']));
        $receiptId = filled($parsed['fields']['referensi'] ?? null) ? 'reference:'.mb_strtolower(trim($parsed['fields']['referensi'])) : $data['event_id'];
        $event = 'custom:'.hash('sha256', $merchant.':'.$receiptId);
        if (CommerceReceipt::where('event', $event)->exists()) {
            return;
        }
        $orders = CustomerOrder::where('store_id', $store->id)->where('gateway', 'custom')->where('merchant_key', $merchant)->where('total', $amount)->where('created_at', '<=', $at)->where('expires_at', '>=', $at)->get();
        app(CustomerCommerce::class)->ensure($orders->count() === 1, 'Tidak ada satu invoice yang cocok. Periksa nominal dan waktu pembayaran.');
        app(CustomerCommerce::class)->paid($orders->first(), $event, $amount, $at);
    }

    public function canConfirmCustom(CustomerOrder $order): bool
    {
        return $order->gateway === 'custom' && $order->payment_status === 'unpaid'
            && in_array($order->status, ['awaiting_payment', 'expired'], true)
            && ! ($order->snapshot['cancelled_by_customer'] ?? false)
            && empty($order->snapshot['cancelled_by_owner']);
    }

    public function confirmCustom(CustomerOrder $order, array $data, string $actor): void
    {
        DB::transaction(function () use ($order, $data, $actor) {
            $store = Store::whereKey($order->store_id)->lockForUpdate()->firstOrFail();
            StoreRetention::guard($store);
            $locked = CustomerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $commerce = app(CustomerCommerce::class);
            $commerce->ensure($locked->gateway === 'custom' && $commerce->settings($store)->custom_licensed_at !== null, 'Konfirmasi manual hanya untuk invoice modul QRIS toko berlisensi.');
            if ($locked->payment_status === 'paid') {
                return;
            }
            $commerce->ensure($this->canConfirmCustom($locked), 'Invoice tidak lagi dapat dikonfirmasi. Muat ulang rincian pesanan.');
            $at = Carbon::parse($data['paid_at'])->utc();
            $commerce->ensure($at->gte($locked->created_at) && $at->lte(now()), 'Waktu pembayaran harus setelah pesanan dibuat dan tidak boleh di masa depan.');
            $commerce->ensure((int) $data['received_amount'] === $locked->total, 'Nominal diterima harus sama dengan total invoice termasuk kode unik.');
            $reference = trim($data['reference']);
            $event = 'custom-manual:'.hash('sha256', $locked->merchant_key.':'.mb_strtolower($reference));
            $locked->update(['snapshot' => [...$locked->snapshot, 'payment_confirmation' => ['actor' => $actor, 'reference' => $reference, 'amount' => (int) $data['received_amount'], 'note' => $data['note'], 'paid_at' => $at->toIso8601String(), 'at' => now()->toIso8601String()]]]);
            $commerce->paid($locked, $event, (int) $data['received_amount'], $at);
        }, 3);
    }
}
