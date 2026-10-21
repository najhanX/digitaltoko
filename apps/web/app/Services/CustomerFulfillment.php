<?php

namespace App\Services;

use App\Jobs\ProcessCustomerOrder;
use App\Models\CustomerOrder;
use App\Models\OrderAttempt;
use App\Models\PremiumStock;
use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Support\HanshopIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CustomerFulfillment
{
    private const DIGIFLAZZ_FAILURES = ['01', '02', '40', '41', '42', '43', '44', '45', '47', '51', '52', '53', '54', '55', '57', '58', '59', '60', '61', '62', '63', '66', '67', '68', '69', '70', '71', '72', '73', '74', '80', '81', '82'];

    public static function isDefinitiveFailure(string $rc): bool
    {
        return in_array($rc, self::DIGIFLAZZ_FAILURES, true);
    }

    public function digiflazzCallback(CustomerOrder $order, OrderAttempt $attempt, array $data): void
    {
        $lock = Cache::lock('customer-fulfill:'.$order->id, 120);
        abort_unless($lock->get(), 503, 'Transaksi sedang diproses. Kirim ulang callback.');
        try {
            DB::transaction(function () use ($order, $attempt, $data) {
                Store::whereKey($order->store_id)->lockForUpdate()->firstOrFail();
                $order = CustomerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $attempt = OrderAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                if ($order->payment_status !== 'paid' || $order->purpose !== 'product' || ! $attempt->last_checked_at || ! in_array($attempt->status, ['dispatching', 'pending', 'success', 'failed'], true)) {
                    return;
                }
                $snapshot = $attempt->request_snapshot;
                if (($snapshot['kind'] ?? '') === 'postpaid' && ! ($snapshot['pay_started'] ?? false)) {
                    return;
                }
                $latest = $order->attempts()->whereNotNull('last_checked_at')->where('status', '!=', 'ready')->orderByDesc('position')->first();
                if ($latest?->id !== $attempt->id) {
                    $attempt->update(['status' => $data['status'] === 'Sukses' && $data['rc'] === '00' ? 'success' : ($data['status'] === 'Gagal' && self::isDefinitiveFailure($data['rc']) ? 'failed' : 'pending'), 'result' => mb_substr((string) ($data['sn'] ?? ''), 0, 20000), 'message' => 'Callback parsing sebelumnya dicatat; pesanan mengikuti parsing terakhir yang telah dikirim.']);

                    return;
                }
                if (empty($order->snapshot['manual_decision']) && empty($order->snapshot['supplier_reconciled']) && in_array($order->status, ['paid', 'processing', 'pending'], true) && $attempt->status === 'failed' && $data['status'] === 'Gagal' && self::isDefinitiveFailure($data['rc'])) {
                    return;
                }
                if (! empty($order->snapshot['manual_decision']) || ! empty($order->snapshot['supplier_reconciled']) || in_array($order->status, ['success', 'failed', 'review'], true) || in_array($attempt->status, ['success', 'failed'], true)) {
                    app(CustomerOrderDecision::class)->callback($order, $attempt, $data);

                    return;
                }
                $rc = $data['rc'];
                $status = $data['status'] === 'Sukses' && $rc === '00' ? 'success' : ($data['status'] === 'Gagal' && in_array($rc, self::DIGIFLAZZ_FAILURES, true) ? 'failed' : 'pending');
                $this->finish($order, $attempt, ['status' => $status, 'cost' => $data['price'] ?? null, 'result' => (string) ($data['sn'] ?? ''), 'price_changed' => $rc === '69', 'message' => $status === 'failed' ? $this->digiflazzFailureMessage($rc, $order->is_sandbox, $data['message'] ?? null) : 'Status diterima melalui webhook Digiflazz.']);
                if ($status === 'failed' && $order->fresh()->status !== 'failed') {
                    if (! $order->attempts()->where('status', '!=', 'failed')->exists()) {
                        app(CustomerCommerce::class)->fail($order->fresh(), 'Semua sumber produk telah gagal atau tidak tersedia.');
                    } else {
                        DB::afterCommit(function () use ($order) {
                            try {
                                ProcessCustomerOrder::dispatch($order->id);
                            } catch (\Throwable $error) {
                                report($error);
                            }
                        });
                    }
                }
            }, 3);
        } finally {
            $lock->release();
        }
    }

    private function digiflazzFailureMessage(string $rc, bool $testing, mixed $supplierMessage = null): string
    {
        return match ($rc) {
            '41' => 'Signature Digiflazz ditolak. Periksa username dan key sesuai mode (RC 41).',
            '42' => 'Username Digiflazz tidak sesuai (RC 42).',
            '43' => 'SKU Digiflazz tidak ditemukan atau nonaktif (RC 43).',
            '44' => 'Saldo Digiflazz tidak cukup (RC 44).',
            '45' => app(DigiflazzNetwork::class)->whitelistMessage($testing, $supplierMessage),
            default => 'Digiflazz menolak transaksi (RC '.preg_replace('/[^0-9]/', '', $rc).').',
        };
    }

    public function run(CustomerOrder $order): void
    {
        Cache::lock('customer-fulfill:'.$order->id, 120)->get(function () use ($order) {
            $started = microtime(true);
            for ($i = 0; $i < 20 && microtime(true) - $started < 35; $i++) {
                $attempt = $this->claim($order);
                if (! $attempt) {
                    return;
                }
                if ($attempt->source === 'stock') {
                    $this->stock($order, $attempt);

                    continue;
                }
                try {
                    $outcome = $this->remote($order->fresh(), $attempt);
                } catch (\Throwable) {
                    $outcome = ['status' => 'pending', 'message' => 'Pemasok belum memberi hasil pasti. Status akan diperiksa kembali.'];
                }
                $this->finish($order, $attempt, $outcome);
                if ($outcome['status'] !== 'failed') {
                    return;
                }
            }
        });
    }

    private function claim(CustomerOrder $order): ?OrderAttempt
    {
        return DB::transaction(function () use ($order) {
            Store::whereKey($order->store_id)->lockForUpdate()->first();
            $order = CustomerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! empty($order->snapshot['manual_decision']) || ! empty($order->snapshot['supplier_reconciled']) || $order->payment_status !== 'paid' || $order->purpose !== 'product' || ! in_array($order->status, ['paid', 'processing', 'pending'], true)) {
                return null;
            }
            if ($order->customer_id && app(CustomerCommerce::class)->purchaseBlocked($order->store, $order->customer, $order->phone)) {
                $order->update(['status' => 'review', 'message' => 'Pembelian ditahan karena saldo pelanggan minus. Pulihkan saldo terlebih dahulu.']);
                app(CustomerCommerce::class)->notice($order, 'review');

                return null;
            }
            $attempt = $order->attempts()->whereNotIn('status', ['failed'])->orderBy('position')->lockForUpdate()->first();
            if (! $attempt) {
                app(CustomerCommerce::class)->fail($order, 'Semua sumber produk telah gagal atau tidak tersedia.');

                return null;
            }
            if ($attempt->status === 'success') {
                $this->success($order, $attempt->result ?? 'Pesanan berhasil.');

                return null;
            }
            if ($attempt->source === 'digiflazz' && $attempt->created_at->lt(now()->subDays(89))) {
                $order->update(['status' => 'review', 'message' => 'Transaksi terlalu lama untuk diperiksa otomatis. Hubungi pemasok dengan referensi yang sama.']);
                app(CustomerCommerce::class)->notice($order, 'review');

                return null;
            }
            if ($attempt->status !== 'ready' && $attempt->last_checked_at?->gt(now()->subMinute())) {
                return null;
            }
            if ($attempt->status === 'ready') {
                $data = $attempt->request_snapshot;
                if ($attempt->source !== 'stock' && ! SupplierConnection::whereKey($data['connection_id'])->where('store_id', $order->store_id)->where('enabled', true)->exists()) {
                    $attempt->update(['status' => 'failed', 'message' => 'Koneksi sumber dinonaktifkan sebelum pengiriman.']);

                    return null;
                }
                $current = $attempt->source === 'stock' ? null : SupplierProduct::where('connection_id', $data['connection_id'])->where('sku', $data['sku'])->first();
                if ($attempt->cost > (int) ($order->snapshot['subtotal'] ?? $order->amount) || ($current && $current->cost > (int) ($order->snapshot['subtotal'] ?? $order->amount))) {
                    app(CustomerCommerce::class)->fail($order, 'Modal pemasok naik melebihi harga jual pesanan.');

                    return null;
                }
            }
            $attempt->update(['status' => 'dispatching', 'last_checked_at' => now()]);
            $order->update(['status' => 'processing']);

            return $attempt;
        }, 3);
    }

    private function stock(CustomerOrder $order, OrderAttempt $attempt): void
    {
        DB::transaction(function () use ($order, $attempt) {
            Store::whereKey($order->store_id)->lockForUpdate()->first();
            $order = CustomerOrder::whereKey($order->id)->lockForUpdate()->first();
            $attempt = OrderAttempt::whereKey($attempt->id)->lockForUpdate()->first();
            if (! in_array($order->status, ['processing', 'pending'], true) || $attempt->status !== 'dispatching') {
                return;
            }
            if ($order->is_sandbox) {
                $attempt->update(['status' => 'success', 'result' => 'SIMULASI: akun-premium@example.test / sandi-uji']);
                $this->success($order, $attempt->result);

                return;
            }
            $stock = PremiumStock::where('store_id', $order->store_id)->where('product_id', $order->product_id)->where('order_reference', $order->number)->first();
            if (! $stock) {
                $stock = PremiumStock::where('store_id', $order->store_id)->where('product_id', $order->product_id)->where('status', 'ready')->whereNull('order_reference')->orderBy('cost')->orderBy('id')->lockForUpdate()->first();
            }
            if (! $stock) {
                $attempt->update(['status' => 'failed', 'message' => 'Stok premium habis.']);

                return;
            }
            if ($stock->cost > (int) ($order->snapshot['subtotal'] ?? $order->amount)) {
                $attempt->update(['status' => 'failed', 'message' => 'Modal stok naik.']);
                app(CustomerCommerce::class)->fail($order, 'Modal stok naik melebihi harga jual pesanan.');

                return;
            }
            $stock->update(['status' => 'sent', 'order_reference' => $order->number]);
            $attempt->update(['status' => 'success', 'cost' => $stock->cost, 'provider_id' => $stock->id, 'result' => $stock->payload]);
            $this->success($order, $stock->payload);
        }, 3);
    }

    private function remote(CustomerOrder $order, OrderAttempt $attempt): array
    {
        $data = $attempt->request_snapshot;
        if ($order->is_sandbox && $attempt->source === 'hanshop') {
            return ['status' => 'success', 'cost' => $attempt->cost, 'result' => 'SIMULASI HANSHOP: '.$attempt->reference];
        }
        if ($attempt->source === 'hanshop') {
            $result = app(HanshopIdentity::class)->call('supplier/transaction', ['id_user' => $data['id_user'], 'store_id' => $order->store_id, 'access_until' => now()->addMinutes(5)->toIso8601String(),
                'reference' => $attempt->reference, 'kind' => $data['kind'], 'product_code' => $data['sku'], 'destination' => $order->destination, 'max_price' => (int) ($order->snapshot['subtotal'] ?? $order->amount)]);
            if (($result['reference'] ?? '') !== $attempt->reference || ($result['id_user'] ?? '') !== $data['id_user']) {
                return ['status' => 'pending', 'message' => 'Referensi hasil pemasok belum cocok.'];
            }
            $status = $result['status'] ?? 'PENDING';

            return ['status' => $status === 'SUCCESS' ? 'success' : ($status === 'FAILED' ? 'failed' : 'pending'), 'result' => (string) ($result['result'] ?? ''),
                'price_changed' => ($result['code'] ?? '') === 'PRICE_CHANGED', 'cost' => $result['price'] ?? null, 'message' => $status === 'FAILED' ? 'Sumber Hanshop menolak transaksi ('.preg_replace('/[^A-Z0-9_]/', '', (string) ($result['code'] ?? 'FAILED')).').' : 'Menunggu hasil Hanshop.'];
        }
        $credentials = $data['credentials'];
        app(CustomerCommerce::class)->ensure((bool) ($credentials['testing'] ?? false) === $order->is_sandbox, 'Lingkungan credential Digiflazz tidak sesuai pesanan.');
        $username = $credentials['username'];
        $key = $credentials['api_key'];
        $payload = ['testing' => $order->is_sandbox, 'username' => $username, 'buyer_sku_code' => $data['sku'], 'customer_no' => $order->destination, 'ref_id' => $attempt->reference, 'sign' => md5($username.$key.$attempt->reference)];
        $postpaid = ($data['kind'] ?? '') === 'postpaid';
        if ($postpaid && ! ($data['pay_started'] ?? false)) {
            if (! $order->created_at->timezone('Asia/Jakarta')->isSameDay(now()->timezone('Asia/Jakarta'))) {
                return ['status' => 'failed', 'message' => 'Tagihan harus diperiksa ulang karena hari pembayaran telah berganti.'];
            }
            $inquiryResponse = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20)->post('https://api.digiflazz.com/v1/transaction', [...$payload, 'commands' => 'inq-pasca']);
            $inquiry = $inquiryResponse->json('data');
            $inquiryFailure = is_array($inquiry) && ($inquiry['status'] ?? '') === 'Gagal' && in_array((string) ($inquiry['rc'] ?? ''), self::DIGIFLAZZ_FAILURES, true);
            if (! $inquiryResponse->successful() && ! ($inquiryResponse->status() === 400 && $inquiryFailure)) {
                return ['status' => 'pending', 'message' => 'Pemeriksaan tagihan belum dapat dipastikan.'];
            }
            if (! is_array($inquiry) || ($inquiry['ref_id'] ?? '') !== $attempt->reference || ($inquiry['buyer_sku_code'] ?? '') !== $data['sku'] || ($inquiry['customer_no'] ?? '') !== $order->destination) {
                return ['status' => 'pending', 'message' => 'Pemeriksaan tagihan belum memperoleh hasil pasti.'];
            }
            if ($inquiryFailure) {
                return ['status' => 'failed', 'message' => $this->digiflazzFailureMessage((string) $inquiry['rc'], $order->is_sandbox, $inquiry['message'] ?? null)];
            }
            if (($inquiry['status'] ?? '') !== 'Sukses' || ($inquiry['rc'] ?? '') !== '00') {
                return ['status' => 'pending', 'message' => 'Pemeriksaan tagihan belum memperoleh hasil pasti.'];
            }
            if (! is_numeric($inquiry['price'] ?? null) || (int) $inquiry['price'] <= 0) {
                return ['status' => 'pending', 'message' => 'Nominal tagihan belum valid.'];
            }
            if ((int) $inquiry['price'] > (int) ($order->snapshot['subtotal'] ?? $order->amount)) {
                return ['status' => 'failed', 'price_changed' => true, 'message' => 'Modal tagihan melebihi harga pesanan.'];
            }
            // Persist before I/O: after a crash or uncertain response only status-pasca is permitted.
            $data['pay_started'] = true;
            $attempt->update(['request_snapshot' => $data]);
            $payload['commands'] = 'pay-pasca';
        } elseif ($postpaid) {
            $payload['commands'] = 'status-pasca';
        } else {
            $payload['max_price'] = (int) ($order->snapshot['subtotal'] ?? $order->amount);
        }
        $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20)->post('https://api.digiflazz.com/v1/transaction', $payload);
        $body = $response->json('data');
        $terminalFailure = is_array($body) && ($body['status'] ?? '') === 'Gagal' && in_array((string) ($body['rc'] ?? ''), self::DIGIFLAZZ_FAILURES, true);
        if (! $response->successful() && ! ($response->status() === 400 && $terminalFailure)) {
            return ['status' => 'pending', 'message' => 'Respons Digiflazz belum dapat dipastikan (HTTP '.$response->status().').'];
        }
        if (! is_array($body) || ($body['ref_id'] ?? '') !== $attempt->reference || ($body['buyer_sku_code'] ?? '') !== $data['sku'] || ($body['customer_no'] ?? '') !== $order->destination) {
            return ['status' => 'pending', 'message' => 'Respons pemasok belum dapat dipastikan.'];
        }
        $status = $body['status'] ?? '';
        $rc = (string) ($body['rc'] ?? '');
        $final = $status === 'Sukses' && $rc === '00' ? 'success' : ($status === 'Gagal' && in_array($rc, self::DIGIFLAZZ_FAILURES, true) ? 'failed' : 'pending');

        return ['status' => $final, 'result' => (string) ($body['sn'] ?? ''), 'cost' => $body['price'] ?? null, 'price_changed' => $rc === '69', 'message' => $final === 'failed' ? $this->digiflazzFailureMessage($rc, $order->is_sandbox, $body['message'] ?? null) : 'Menunggu hasil Digiflazz.'];
    }

    private function finish(CustomerOrder $order, OrderAttempt $attempt, array $outcome): void
    {
        DB::transaction(function () use ($order, $attempt, $outcome) {
            Store::whereKey($order->store_id)->lockForUpdate()->first();
            $order = CustomerOrder::whereKey($order->id)->lockForUpdate()->first();
            $attempt = OrderAttempt::whereKey($attempt->id)->lockForUpdate()->first();
            if (in_array($order->status, ['success', 'failed', 'review'], true) || in_array($attempt->status, ['success', 'failed'], true)) {
                return;
            }
            $status = $outcome['status'];
            $cost = $outcome['cost'] ?? null;
            if ($status === 'success' && (! is_numeric($cost) || (int) $cost < 0 || (int) $cost > (int) ($order->snapshot['subtotal'] ?? $order->amount))) {
                $attempt->update(['status' => 'pending', 'result' => mb_substr((string) ($outcome['result'] ?? ''), 0, 20000), 'message' => 'Harga hasil pemasok perlu ditinjau.']);
                $order->update(['status' => 'review', 'message' => 'Hasil pemasok perlu diperiksa toko.']);
                app(CustomerCommerce::class)->notice($order, 'review');

                return;
            }
            $attempt->update(['status' => $status, 'message' => $outcome['message'] ?? null, ...($cost !== null && is_numeric($cost) && $cost >= 0 ? ['cost' => (int) $cost] : []), ...($status === 'success' ? ['result' => mb_substr($outcome['result'] ?: 'Pesanan berhasil.', 0, 20000)] : [])]);
            if ($status === 'success') {
                $this->success($order, $attempt->result);
            } elseif ($status === 'failed' && ($outcome['price_changed'] ?? false)) {
                app(CustomerCommerce::class)->fail($order, 'Modal pemasok naik melebihi batas harga pesanan.');
            } elseif ($status === 'pending') {
                $order->update(['status' => 'pending', 'message' => 'Transaksi sedang diproses pemasok. Silakan cek kembali.']);
            }
        }, 3);
    }

    private function success(CustomerOrder $order, string $result): void
    {
        $order->update(['status' => 'success', 'result' => $result, 'completed_at' => now(), 'message' => 'Pesanan berhasil diproses.']);
        app(CustomerCommerce::class)->notice($order, 'success');
    }
}
