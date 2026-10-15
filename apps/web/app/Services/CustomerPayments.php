<?php

namespace App\Services;

use App\Models\CommerceReceipt;
use App\Models\CustomerOrder;
use App\Models\Store;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CustomerPayments
{
    private function base(CustomerOrder $order): string
    {
        return ($order->snapshot['sandbox'] ?? true) ? 'https://api.sandbox.midtrans.com/v2/' : 'https://api.midtrans.com/v2/';
    }

    private function client(CustomerOrder $order): PendingRequest
    {
        return Http::withBasicAuth($order->payment_credentials['server_key'], '')->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20);
    }

    public function start(CustomerOrder $order): void
    {
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
            if ($order->gateway !== 'midtrans' || $order->provider_id) {
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
            $image = $this->base($order).'qris/'.rawurlencode($id).'/qr-code';
            $order->update(['provider_id' => $id, 'qr_image' => $image]);
        });
    }

    private function verifyAmount(CustomerOrder $order, array $data): void
    {
        app(CustomerCommerce::class)->ensure(($data['order_id'] ?? '') === $order->number && preg_match('/^[0-9]+(?:\.00)?$/', (string) ($data['gross_amount'] ?? '')) && (int) $data['gross_amount'] === $order->total && ($data['currency'] ?? 'IDR') === 'IDR', 'Nominal atau referensi Midtrans tidak sesuai.');
    }

    public function refresh(CustomerOrder $order): void
    {
        if ($order->gateway !== 'midtrans' || $order->payment_status === 'paid') {
            return;
        }
        $response = $this->client($order)->get($this->base($order).rawurlencode($order->number).'/status');
        if ((string) $response->json('status_code') === '404') {
            return;
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
                Store::whereKey($order->store_id)->lockForUpdate()->first();
                $locked = CustomerOrder::whereKey($order->id)->lockForUpdate()->first();
                if ($locked->payment_status === 'unpaid' && $locked->status === 'awaiting_payment') {
                    $locked->update(['status' => 'expired', 'message' => 'Pembayaran tidak diselesaikan.']);
                }
            });
        } elseif (! $order->provider_id && is_string($data['transaction_id'] ?? null)) {
            $id = $data['transaction_id'];
            app(CustomerCommerce::class)->ensure(preg_match('/^[a-zA-Z0-9-]{1,100}$/', $id) === 1, 'Referensi pembayaran tidak valid.');
            $order->update(['provider_id' => $id, 'qr_image' => $this->base($order).'qris/'.rawurlencode($id).'/qr-code']);
        }
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

    public function custom(Store $store, array $data, ?array $context = null): void
    {
        $setting = app(CustomerCommerce::class)->settings($store);
        $credentials = $context ?? $setting->credentials ?? [];
        app(CustomerCommerce::class)->ensure($setting->custom_licensed_at !== null, 'Lisensi QRIS custom tidak aktif.');
        abort_unless(($data['application_package'] ?? '') === ($credentials['application_package'] ?? 'id.dana'), 422);
        $text = ($data['title'] ?? '').' '.($data['text'] ?? '');
        app(CustomerCommerce::class)->ensure(preg_match('/(?:pembayaran|uang|saldo|transfer).*(?:diterima|masuk)|(?:terima|menerima).*(?:pembayaran|uang|transfer)/iu', $text) === 1 && ! preg_match('/(?:gagal|dibatalkan|keluar|berhasil dikirim)/iu', $text), 'Notifikasi bukan pembayaran masuk.');
        preg_match_all('/Rp\s*([0-9][0-9.]*(?:,[0-9]{2})?)/iu', $text, $matches);
        $amounts = array_unique(array_map(fn ($s) => (int) str_replace('.', '', explode(',', $s)[0]), $matches[1]));
        app(CustomerCommerce::class)->ensure(count($amounts) === 1 && reset($amounts) > 0, 'Nominal notifikasi tidak jelas.');
        $amount = (int) reset($amounts);
        $at = Carbon::parse($data['occurred_at'])->utc();
        app(CustomerCommerce::class)->ensure($at->lte(now()->addSeconds(30)) && $at->gte(now()->subDay()), 'Waktu notifikasi di luar batas pemeriksaan.');
        $merchant = hash('sha256', app(CustomQris::class)->identity($credentials['qris_payload']));
        $event = 'custom:'.hash('sha256', $merchant.':'.$data['event_id']);
        if (CommerceReceipt::where('event', $event)->exists()) {
            return;
        }
        $orders = CustomerOrder::where('store_id', $store->id)->where('gateway', 'custom')->where('merchant_key', $merchant)->where('total', $amount)->where('created_at', '<=', $at)->where('expires_at', '>=', $at)->get();
        app(CustomerCommerce::class)->ensure($orders->count() === 1, 'Tidak ada satu invoice yang cocok. Periksa nominal dan waktu pembayaran.');
        app(CustomerCommerce::class)->paid($orders->first(), $event, $amount, $at);
    }
}
