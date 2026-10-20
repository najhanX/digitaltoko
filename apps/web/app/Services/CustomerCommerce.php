<?php

namespace App\Services;

use App\Models\CatalogProduct;
use App\Models\CommerceReceipt;
use App\Models\Customer;
use App\Models\CustomerInquiry;
use App\Models\CustomerNotice;
use App\Models\CustomerOrder;
use App\Models\OrderAttempt;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StorePaymentSetting;
use App\Support\HanshopIdentity;
use App\Support\PlatformHost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerCommerce
{
    public function settings(Store $store): StorePaymentSetting
    {
        return StorePaymentSetting::firstOrCreate(['store_id' => $store->id]);
    }

    public function ensure(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['commerce' => $message]);
        }
    }

    public function begin(Store $store, array $input, ?Customer $customer, string $channel = 'web'): CustomerOrder
    {
        $this->ensure(in_array($channel, ['web', 'bot'], true), 'Kanal pesanan tidak valid.');
        $phone = $customer?->phone ?? HanshopIdentity::phone($input['phone'] ?? '');
        $purpose = $input['purpose'] ?? 'product';
        $pay = $input['payment'] ?? 'gateway';
        $this->ensure(in_array($purpose, ['product', 'topup'], true) && in_array($pay, ['gateway', 'wallet'], true), 'Jenis pesanan tidak valid.');
        $key = $input['idempotency_key'] ?? '';
        $this->ensure(is_string($key) && preg_match('/^[a-zA-Z0-9._:-]{8,128}$/', $key) === 1, 'Referensi pesanan tidak valid.');
        $destination = trim((string) ($input['destination'] ?? $phone));
        if ($purpose === 'product' && $channel === 'web') {
            $targetProduct = CatalogProduct::with('taxonomyCategory.service')->where('store_id', $store->id)->findOrFail($input['product_id'] ?? '');
            $destination = app(CategoryDestination::class)->destination($store, $targetProduct, $input, $phone);
            $input['destination'] = $destination;
        }
        $this->ensure($destination !== '' && mb_strlen($destination) <= 255 && ! preg_match('/[\r\n]/', $destination), 'Tujuan transaksi wajib diisi.');
        $email = $customer?->email ?? ($input['email'] ?? null);
        $hash = hash('sha256', json_encode([...($store->sandbox ? ['sandbox'] : []), $purpose, $input['product_id'] ?? null, $input['amount'] ?? null, $destination, $phone, $email, $customer?->id, $pay, $channel, $input['inquiry_id'] ?? null]));
        if ($purpose === 'product') {
            app(SupplierCatalog::class)->refreshForStore($store);
        }

        return DB::transaction(function () use ($store, $input, $customer, $channel, $phone, $purpose, $pay, $key, $destination, $email, $hash) {
            $expectedSandbox = $store->sandbox;
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $this->ensure($store->sandbox === $expectedSandbox, 'Mode toko berubah. Muat ulang halaman sebelum memesan.');
            $existing = CustomerOrder::where('store_id', $store->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                $this->ensure(hash_equals($existing->request_hash, $hash), 'Referensi sudah dipakai untuk pesanan berbeda.');

                return $existing;
            }
            if (! $customer && $channel === 'web' && isset($input['guest_checkout_hash'])) {
                $this->ensure(! app(GuestCheckout::class)->pending($store, $input['guest_checkout_hash']), 'Masih ada pesanan menunggu pembayaran. Selesaikan atau batalkan pesanan sebelumnya.');
            }
            $setting = $this->settings($store);
            $this->ensure($store->acceptsOrders() && ! $setting->closing, 'Toko tidak menerima pesanan atau topup baru.');
            $this->ensure(! $customer || ($customer->store_id === $store->id && ($customer->phone_verified_at || $customer->email_verified_at)), 'Akun pelanggan tidak sesuai toko.');
            $this->ensure($customer || ($purpose === 'product' && $setting->allow_guest), 'Masuk ke akun pelanggan terlebih dahulu.');
            $this->ensure($purpose !== 'topup' || ($customer && $pay === 'gateway'), 'Topup wajib memakai akun pelanggan dan gateway toko.');
            $inquiry = null;
            $snapshot = [];
            $candidates = [];
            $product = null;
            if ($purpose === 'product') {
                $product = app(StoreCatalog::class)->query($store, false)->whereKey($input['product_id'] ?? '')->firstOrFail();
                $quote = app(StoreCatalog::class)->present($product, $store, true);
                $this->ensure($quote['available'], 'Produk tidak tersedia atau harga jual di bawah modal.');

                $amount = $quote['price'];

                $candidates = $quote['candidates'];
                $snapshot = $product->only(['code', 'name', 'kind', 'service', 'category']);
                if ($channel === 'web') {
                    $snapshot['game_account'] = app(GameIdentity::class)->checkout($store, $product, $input);
                }
                if ($product->kind === 'postpaid') {
                    $inquiry = CustomerInquiry::where('store_id', $store->id)->where('product_id', $product->id)->whereKey($input['inquiry_id'] ?? '')->lockForUpdate()->first();
                    $this->ensure($inquiry && (bool) ($inquiry->data['sandbox'] ?? false) === $store->sandbox && $inquiry->phone === $phone && $inquiry->destination === $destination && $inquiry->expires_at->isFuture(), 'Periksa tagihan kembali sebelum membayar.');
                    $this->ensure(! CustomerOrder::where('inquiry_id', $inquiry->id)->exists(), 'Pemeriksaan tagihan sudah dipakai pesanan lain.');
                    $amount = $inquiry->amount;
                    $candidates = $inquiry->data['candidates'];
                    $snapshot['bill_customer'] = $inquiry->data['customer_name'];
                    $snapshot['bill_period'] = $inquiry->data['period'];
                }

            } else {
                $amount = (int) ($input['amount'] ?? 0);
                $this->ensure($amount >= 5000 && $amount <= 100000000, 'Nominal topup Rp5.000–Rp100.000.000.');
                $snapshot = ['name' => 'Topup saldo pelanggan', 'kind' => 'topup'];
            }
            $this->ensure(! isset($input['expected_price']) || (int) $input['expected_price'] === $amount, 'Harga berubah. Periksa harga terbaru sebelum mengonfirmasi pesanan.');
            $this->ensure($amount > 0 && $amount <= 100000000, 'Nominal pesanan tidak valid.');
            $gateway = $pay === 'wallet' ? 'wallet' : app(StoreEnvironment::class)->gateway($store, $setting);
            $this->ensure($gateway === 'wallet' ? $customer !== null : in_array($gateway, ['midtrans', 'custom', 'sandbox'], true), 'Gateway pembayaran toko belum disiapkan.');
            $credentials = $setting->credentials ?? [];
            $this->ensure($gateway !== 'custom' || $setting->custom_licensed_at !== null, 'Lisensi QRIS custom toko belum aktif.');
            $this->ensure($gateway !== 'midtrans' || filled($credentials['server_key'] ?? null), 'Server key Midtrans belum diatur.');
            $this->ensure($gateway !== 'custom' || filled($credentials['qris_payload'] ?? null), 'QRIS toko belum diatur.');
            $fee = $gateway === 'midtrans' && $setting->fee_payer === 'customer' ? app(ProductPricing::class)->apply($amount, $setting->fee_markup) - $amount : 0;
            $unique = 0;
            $merchant = null;
            $active = null;
            if ($gateway === 'custom') {
                $merchant = hash('sha256', app(CustomQris::class)->identity($credentials['qris_payload']));
                DB::table('commerce_locks')->insertOrIgnore(['id' => $merchant]);
                DB::table('commerce_locks')->where('id', $merchant)->lockForUpdate()->first();
                $offset = random_int(0, 998);
                for ($i = 0; $i < 999; $i++) {
                    $unique = 1 + (($offset + $i) % 999);
                    $candidate = hash('sha256', $merchant.':'.($amount + $unique));
                    if (! CustomerOrder::where('active_amount_key', $candidate)->exists()) {
                        $active = $candidate;
                        break;
                    }
                }
                $this->ensure($active !== null, 'Kode pembayaran sedang penuh. Coba beberapa saat lagi.');
            }
            $total = $amount + $fee + $unique;
            $order = CustomerOrder::create(['store_id' => $store->id, 'customer_id' => $customer?->id, 'product_id' => $product?->id, 'inquiry_id' => $inquiry?->id, 'is_sandbox' => $store->sandbox, 'number' => ($store->sandbox ? 'SB' : 'DT').Str::ulid(),
                'guest_checkout_hash' => ! $customer && $channel === 'web' ? ($input['guest_checkout_hash'] ?? null) : null, 'idempotency_key' => $key, 'request_hash' => $hash, 'channel' => $channel, 'purpose' => $purpose, 'gateway' => $gateway, 'phone' => $phone, 'email' => $email, 'destination' => $destination,
                'snapshot' => [...$snapshot, 'refund_unique' => $setting->refund_unique, 'topup_unique' => $setting->topup_unique, 'fee_payer' => $setting->fee_payer, 'sandbox' => $setting->sandbox],
                'amount' => $amount, 'fee' => $fee, 'unique_code' => $unique, 'total' => $total, 'credit_amount' => $purpose === 'topup' ? $amount + ($gateway === 'custom' && $setting->topup_unique ? $unique : 0) : 0,
                'refund_amount' => $amount + ($gateway === 'custom' && $setting->refund_unique ? $unique : 0), 'merchant_key' => $merchant, 'active_amount_key' => $active,
                'payment_credentials' => in_array($gateway, ['wallet', 'sandbox'], true) ? null : $credentials, 'expires_at' => $inquiry?->expires_at ?? now()->addMinutes(15)]);
            foreach ($candidates as $index => $candidate) {
                $mapping = ProductMapping::with('item.connection')->findOrFail($candidate['mapping_id']);
                $connection = $mapping->item?->connection;
                OrderAttempt::create(['order_id' => $order->id, 'reference' => $inquiry && $index === 0 ? $inquiry->reference : 'DT'.Str::ulid(), 'position' => $index + 1, 'source' => $candidate['source'], 'cost' => $candidate['cost'],
                    'request_snapshot' => ['sku' => $mapping->item?->sku, 'mode' => $mapping->item?->mode, 'kind' => $product->kind, 'connection_id' => $connection?->id,
                        'testing' => $order->is_sandbox, 'credentials' => $connection?->kind === 'digiflazz' ? app(StoreEnvironment::class)->credentials($connection) : [], 'id_user' => $store->owner->hanshop_user_id]]);
            }
            if ($gateway === 'wallet') {
                app(CustomerWallet::class)->apply($customer, -$amount, 'order:'.$order->id.':debit', 'purchase', [], $order->is_sandbox);
                $order->update(['payment_status' => 'paid', 'status' => 'paid', 'paid_at' => now()]);
                $this->notice($order, 'paid');
            } elseif ($channel === 'web') {
                $this->notice($order, 'awaiting_payment');
            }

            return $order;
        }, 3);
    }

    public function paid(CustomerOrder $order, string $event, int $amount, \DateTimeInterface $paidAt): void
    {
        DB::transaction(function () use ($order, $event, $amount, $paidAt) {
            Store::whereKey($order->store_id)->lockForUpdate()->firstOrFail();
            $order = CustomerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $receipt = CommerceReceipt::where('event', $event)->first();
            if ($receipt) {
                $this->ensure($receipt->order_id === $order->id && $receipt->amount === $amount, 'Bukti pembayaran sudah dipakai.');

                return;
            }
            $this->ensure($amount === $order->total, 'Nominal pembayaran tidak sesuai.');
            $this->ensure($paidAt <= now()->addSeconds(30), 'Waktu pembayaran belum valid.');
            if ($order->payment_status === 'paid') {
                return;
            }
            $this->ensure($order->gateway !== 'wallet', 'Pesanan saldo tidak menerima pembayaran gateway.');
            CommerceReceipt::create(['order_id' => $order->id, 'event' => $event, 'amount' => $amount, 'paid_at' => $paidAt]);
            if ($order->status !== 'awaiting_payment' || $paidAt > $order->expires_at || $paidAt < $order->created_at->copy()->subSeconds(30)) {
                $order->update(['payment_status' => 'paid', 'paid_at' => $paidAt, 'status' => 'review', 'message' => 'Pembayaran terlambat atau membutuhkan pemeriksaan toko.']);
                $this->notice($order, 'review');

                return;
            }
            $order->update(['payment_status' => 'paid', 'paid_at' => $paidAt, 'status' => 'paid', 'message' => 'Pembayaran diterima.']);
            if ($order->purpose === 'topup') {
                app(CustomerWallet::class)->apply($order->customer, $order->credit_amount, 'order:'.$order->id.':topup', 'topup', [], $order->is_sandbox);
                $order->update(['status' => 'success', 'completed_at' => now()]);
            }
            $this->notice($order, $order->status);
        }, 3);
    }

    public function fail(CustomerOrder $order, string $message): void
    {
        $this->ensure(DB::transactionLevel() > 0, 'Perubahan status harus atomik.');
        if (in_array($order->status, ['success', 'failed'], true)) {
            return;
        }
        $order->update(['status' => 'failed', 'message' => $message, 'completed_at' => now()]);
        if ($order->payment_status === 'paid' && $order->customer_id && ! $order->refunded_at) {
            app(CustomerWallet::class)->apply($order->customer, $order->refund_amount, 'order:'.$order->id.':refund', 'refund', [], $order->is_sandbox);
            $order->update(['refunded_at' => now()]);
        }
        $this->notice($order, 'failed');
    }

    public function token(CustomerOrder $order): string
    {
        return hash_hmac('sha256', 'customer-order:'.$order->id, (string) config('app.key'));
    }

    public function url(CustomerOrder $order): string
    {
        return rtrim(app(PlatformHost::class)->storeUrl($order->store), '/').'/orders/'.$order->id.'?access='.$this->token($order);
    }

    public function notice(CustomerOrder $order, string $status): void
    {
        $labels = ['awaiting_payment' => 'Pesanan dibuat · menunggu pembayaran', 'paid' => 'Pembayaran diterima', 'pending' => 'Transaksi masih pending. Silakan cek status pesanan secara berkala', 'success' => 'Pesanan berhasil', 'failed' => 'Pesanan gagal', 'review' => 'Pesanan memerlukan pemeriksaan toko'];
        $message = ($order->is_sandbox ? '[PENGUJIAN] ' : '').$order->store->name.' · '.$order->number.'\n'.($labels[$status] ?? $status).'\n'.($order->message ?? '');
        if ($status === 'awaiting_payment') {
            $message .= '\nProduk: '.($order->snapshot['name'] ?? 'Produk digital').'\nTotal: Rp'.number_format($order->total, 0, ',', '.').'\nSelesaikan pembayaran melalui tautan pesanan berikut.';
        }
        if ($order->refunded_at) {
            $message .= '\nDana Rp'.number_format($order->refund_amount, 0, ',', '.').' dikembalikan ke saldo pelanggan toko.';
        }
        if ($status === 'failed' && ! $order->customer_id) {
            $message .= '\nHubungi toko untuk tindak lanjut pembelian ulang atau refund manual.';
        }
        $message .= '\n'.$this->url($order);
        CustomerNotice::firstOrCreate(['event' => 'order:'.$order->id.':'.$status], ['store_id' => $order->store_id, 'order_id' => $order->id, 'phone' => $order->phone, 'email' => $order->email, 'message' => str_replace('\n', "\n", $message)]);
        app(PanelNotifications::class)->forStore($order->store, 'Pesanan '.$order->number, $labels[$status] ?? $status, $status === 'failed' ? 'warning' : 'info', 'order:'.$order->id.':'.$status, 'orders.view');
    }

    public function publicData(CustomerOrder $order): array
    {
        return [...$order->only(['id', 'number', 'is_sandbox', 'purpose', 'status', 'payment_status', 'destination', 'amount', 'fee', 'unique_code', 'total', 'credit_amount', 'refund_amount', 'gateway', 'message', 'created_at', 'expires_at', 'paid_at', 'refunded_at']),
            'can_simulate_payment' => app(CustomerPayments::class)->canSimulate($order), 'simulated_payment' => ($order->snapshot['payment_simulation']['method'] ?? null) === 'local',
            'midtrans_sandbox' => $order->gateway === 'midtrans' && (bool) ($order->snapshot['sandbox'] ?? true),
            'sandbox_qr_url' => $order->gateway === 'midtrans' && ($order->snapshot['sandbox'] ?? true) && is_string($order->provider_id) && preg_match('/^[a-zA-Z0-9-]{1,100}$/', $order->provider_id) ? 'https://api.sandbox.midtrans.com/v2/qris/'.rawurlencode($order->provider_id).'/qr-code' : null,
            'cancelled' => (bool) ($order->snapshot['cancelled_by_customer'] ?? false) || filled($order->snapshot['cancelled_by_owner'] ?? null), 'product' => $order->snapshot['name'], 'game_account' => $order->snapshot['game_account'] ?? null, 'phone' => $order->phone, 'result' => $order->status === 'success' ? $order->result : null, 'payment_url' => $order->payment_url, 'qr_image' => $order->qr_image, 'url' => $this->url($order)];
    }
}
