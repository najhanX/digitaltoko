<?php

namespace App\Services;

use App\Models\CustomerInquiry;
use App\Models\CustomerOrder;
use App\Models\Store;
use App\Models\StorePaymentSetting;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use Illuminate\Support\Facades\DB;

class StoreEnvironment
{
    public function change(Store $store, bool $sandbox): void
    {
        DB::transaction(function () use ($store, $sandbox) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            if ($locked->sandbox === $sandbox) {
                return;
            }
            $engine = app(CustomerCommerce::class);
            $running = CustomerOrder::where('store_id', $store->id)->whereIn('status', ['awaiting_payment', 'paid', 'processing', 'pending', 'review'])->orderBy('created_at')->limit(3)->pluck('number');
            $engine->ensure($running->isEmpty(), 'Mode toko belum dapat diubah. Pesanan masih berjalan: '.$running->implode(', ').'. Buka Transaksi pelanggan untuk menyelesaikan pesanan atau membatalkan invoice yang belum dibayar.');
            $engine->ensure(! CustomerInquiry::where('store_id', $store->id)->where('expires_at', '>', now())->exists(), 'Tunggu pemeriksaan tagihan kedaluwarsa sebelum mengganti mode toko.');
            $locked->update(['sandbox' => $sandbox]);
            SupplierConnection::where('store_id', $store->id)->update(['synced_at' => null, 'last_error' => null]);
            SupplierProduct::where('store_id', $store->id)->update(['available' => false]);
            app(PanelNotifications::class)->forStore($locked, 'Mode toko diubah', $sandbox ? 'Mode Pengujian aktif. Saldo uji terpisah; sinkronkan ulang sumber produk.' : 'Mode Produksi aktif. Hubungkan key produksi dan sinkronkan ulang sumber produk.', 'warning', 'environment:'.$store->id.':'.bin2hex(random_bytes(8)), 'products.view');
        });
    }

    public function gateway(Store $store, StorePaymentSetting $setting): ?string
    {
        if (! $store->sandbox) {
            return $setting->gateway;
        }

        return $setting->gateway === 'midtrans' && $setting->sandbox && filled($setting->credentials['server_key'] ?? null) ? 'midtrans' : 'sandbox';
    }

    public function credentials(SupplierConnection $connection): array
    {
        $credentials = $connection->credentials ?? [];
        $testing = (bool) $connection->store->sandbox;
        $key = $credentials[$testing ? 'development_key' : 'api_key'] ?? '';
        app(CustomerCommerce::class)->ensure(filled($credentials['username'] ?? null) && filled($key), $testing ? 'Isi username dan API key development Digiflazz untuk mode Pengujian.' : 'Isi username dan API key produksi Digiflazz untuk mode Produksi.');

        return ['username' => $credentials['username'], 'api_key' => $key, 'testing' => $testing];
    }
}
