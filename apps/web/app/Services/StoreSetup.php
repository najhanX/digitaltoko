<?php

namespace App\Services;

use App\Models\PremiumStock;
use App\Models\Store;
use App\Models\StorePaymentSetting;
use App\Models\SupplierConnection;
use App\Support\BotConnection;

class StoreSetup
{
    public function summary(Store $store, bool $owner = true, array $permissions = []): array
    {
        $base = '/stores/'.$store->id;
        $can = fn (array $grants): bool => $owner || count(array_intersect($permissions, $grants)) > 0;
        $hasStock = PremiumStock::where('store_id', $store->id)->where('status', 'ready')
            ->whereHas('product', fn ($query) => $query->where('store_id', $store->id)->where('kind', 'premium'))->exists();
        $hasConnection = SupplierConnection::where('store_id', $store->id)->where('enabled', true)->whereNotNull('verified_at')->exists();
        $stockLink = $can(['stock.view', 'stock.manage', 'stock.credentials']) ? $base.'/stock' : null;
        $sourceLink = $can(['products.view', 'products.manage', 'pricing.manage', 'suppliers.manage', 'suppliers.responses', 'suppliers.import', 'suppliers.deposit', 'suppliers.balance']) ? $base.'/supplier-connections/digiflazz' : null;
        $catalogReady = false;
        $catalog = app(StoreCatalog::class);
        $catalog->query($store, false)->where('active', true)->chunkById(100, function ($products) use ($store, $catalog, &$catalogReady) {
            foreach ($products as $product) {
                $quote = $catalog->present($product, $store, true);
                if ($quote['price'] > 0 && $quote['cost'] !== null && ! $quote['price_blocked']
                    && count(array_filter($quote['candidates'], fn ($candidate) => $candidate['quantity'] === null || $candidate['quantity'] > 0)) > 0) {
                    $catalogReady = true;

                    return false;
                }
            }
        });
        $payment = StorePaymentSetting::where('store_id', $store->id)->first();
        $gateway = $payment ? app(StoreEnvironment::class)->gateway($store, $payment) : ($store->sandbox ? 'sandbox' : null);
        $paymentReady = ! $payment?->closing && match ($gateway) {
            'sandbox' => true,
            'midtrans' => filled($payment?->credentials['server_key'] ?? null) && (bool) $payment->sandbox === (bool) $store->sandbox,
            'custom' => $payment?->custom_licensed_at !== null && filled($payment?->credentials['qris_payload'] ?? null) && filled($payment?->credentials['forward_token'] ?? null),
            default => false,
        };
        $botReady = (BotConnection::state($store, false)['status'] ?? '') === 'CONNECTED';
        $items = [
            ['key' => 'source', 'title' => 'Sumber produk', 'ready' => $hasStock || $hasConnection, 'required' => true,
                'description' => $hasStock ? 'Stok premium toko sudah tersedia.' : ($hasConnection ? 'Pemasok terhubung. Perbarui katalog sumber secara berkala.' : 'Hubungkan pemasok atau isi stok premium milik toko.'),
                'href' => $hasStock && ! $hasConnection ? $stockLink : ($sourceLink ?? $stockLink), 'action' => $hasStock && ! $hasConnection || ! $sourceLink && $stockLink ? 'Kelola stok' : 'Atur sumber'],
            ['key' => 'catalog', 'title' => 'Katalog & harga', 'ready' => $catalogReady, 'required' => true,
                'description' => $catalogReady ? 'Ada produk aktif dengan sumber tersedia dan harga jual mencukupi.' : 'Siapkan produk aktif, parsing atau stok tersedia, dan harga jual minimal sebesar modal. Sumber kedaluwarsa perlu disinkronkan.',
                'href' => $can(['products.view', 'products.manage', 'pricing.manage', 'suppliers.manage', 'suppliers.responses', 'suppliers.import', 'suppliers.deposit', 'suppliers.balance']) ? $base.'/products' : null, 'action' => 'Atur katalog'],
            ['key' => 'payment', 'title' => 'Pembayaran pelanggan', 'ready' => $paymentReady, 'required' => true,
                'description' => $payment?->closing ? 'Penerimaan pesanan sedang ditutup.' : ($paymentReady ? ($gateway === 'sandbox' ? 'Simulasi pembayaran aktif untuk pengujian, tanpa uang nyata.' : 'Konfigurasi pembayaran tersimpan. Uji pembayaran dan callback sebelum berjualan.') : 'Pilih Midtrans sesuai mode toko, atau QRIS custom berlisensi beserta koneksi forwarder.'),
                'href' => $can(['payments.view', 'payments.policy', 'checkout.manage']) ? $base.'/payments' : null, 'action' => 'Atur pembayaran'],
        ];
        if ($store->supportsWeb()) {
            $domain = $store->domain;
            $customActive = $domain?->status === 'active' && $domain->verified_at;
            $items[] = ['key' => 'domain', 'title' => 'Alamat web toko', 'ready' => filled($store->slug), 'required' => true,
                'description' => $customActive ? 'Domain pribadi aktif. Subdomain bawaan tetap tersedia.' : ($domain ? 'Subdomain bawaan siap. Domain pribadi masih perlu verifikasi DNS/HTTPS.' : 'Subdomain bawaan siap digunakan. Domain pribadi opsional dan dapat dihubungkan kapan saja.'),
                'href' => $owner ? '/owner/stores/'.$store->id.'/manage#domain-pribadi' : null, 'action' => 'Kelola domain'];
        }
        $items[] = ['key' => 'whatsapp', 'title' => $store->category === 'web' ? 'WhatsApp notifikasi' : 'Bot WhatsApp', 'ready' => $botReady, 'required' => $store->category !== 'web',
            'description' => $botReady ? 'Nomor WhatsApp toko terhubung.' : ($store->category === 'web' ? 'Opsional untuk aktivasi. Hubungkan nomor toko untuk OTP WhatsApp dan notifikasi pelanggan.' : 'Hubungkan nomor toko melalui QR atau kode pairing sebelum aktivasi.'),
            'href' => $can(['bot.view', 'bot.manage']) ? $base.'/bot' : null, 'action' => 'Hubungkan WhatsApp'];
        $required = array_values(array_filter($items, fn ($item) => $item['required']));
        $completed = count(array_filter($required, fn ($item) => $item['ready']));

        return ['items' => $items, 'completed' => $completed, 'total' => count($required), 'ready' => $completed === count($required), 'sandbox' => (bool) $store->sandbox];
    }
}
