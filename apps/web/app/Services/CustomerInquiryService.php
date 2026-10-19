<?php

namespace App\Services;

use App\Models\CustomerInquiry;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Support\HanshopIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CustomerInquiryService
{
    public function quote(Store $store, string $productId, string $destination, string $phone, string $key): CustomerInquiry
    {
        $engine = app(CustomerCommerce::class);
        $engine->ensure($store->acceptsOrders() && ! $engine->settings($store)->closing, 'Toko tidak menerima pesanan baru.');
        $phone = HanshopIdentity::phone($phone);
        $hash = hash('sha256', json_encode([...($store->sandbox ? ['sandbox'] : []), $store->id, $productId, $destination, $phone]));
        $requestKey = hash('sha256', $store->id.':'.$key);

        return Cache::lock('inquiry:'.$requestKey, 45)->block(3, function () use ($engine, $store, $productId, $destination, $phone, $hash, $requestKey) {
            $old = CustomerInquiry::where('request_key', $requestKey)->first();
            if ($old) {
                $engine->ensure(hash_equals($old->request_hash, $hash), 'Referensi cek tagihan sudah dipakai.');

                return $old;
            }
            $product = app(StoreCatalog::class)->query($store)->whereKey($productId)->firstOrFail();
            $quote = app(StoreCatalog::class)->present($product, $store, true);
            $engine->ensure($product->kind === 'postpaid' && $quote['available'], 'Produk tagihan tidak tersedia.');
            $candidates = array_values(array_filter($quote['candidates'], fn ($row) => $row['source'] === 'digiflazz'));
            $engine->ensure(count($candidates) > 0, 'Tagihan memerlukan sumber Digiflazz pascabayar.');
            foreach ($candidates as $position => $candidate) {
                $mapping = ProductMapping::with('item.connection')->findOrFail($candidate['mapping_id']);
                $credentials = app(StoreEnvironment::class)->credentials($mapping->item->connection);
                $reference = 'DT'.Str::ulid();
                try {
                    $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20)->post('https://api.digiflazz.com/v1/transaction', [
                        'testing' => $store->sandbox, 'commands' => 'inq-pasca', 'username' => $credentials['username'], 'buyer_sku_code' => $mapping->item->sku, 'customer_no' => $destination, 'ref_id' => $reference, 'sign' => md5($credentials['username'].$credentials['api_key'].$reference)]);
                    $data = $response->json('data');
                } catch (\Throwable) {
                    continue;
                }
                if (! $response->successful() || ! is_array($data) || ($data['ref_id'] ?? '') !== $reference || ($data['customer_no'] ?? '') !== $destination || ($data['buyer_sku_code'] ?? '') !== $mapping->item->sku || ($data['status'] ?? '') !== 'Sukses' || ($data['rc'] ?? '') !== '00') {
                    continue;
                }
                $cost = $data['price'] ?? null;
                $engine->ensure(is_int($cost) && $cost > 0 && $cost <= 100000000, 'Nominal tagihan pemasok tidak valid.');
                $amount = $cost + ($quote['price'] - $candidate['cost']);
                $engine->ensure($amount >= $cost && $amount <= 100000000, 'Harga jual tagihan di bawah modal.');
                $expiry = now()->addMinutes(5);
                $dayEnd = now()->timezone('Asia/Jakarta')->endOfDay()->subSeconds(30)->utc();
                if ($dayEnd->lt($expiry)) {
                    $expiry = $dayEnd;
                }
                $engine->ensure($expiry->gt(now()->addSeconds(30)), 'Pemeriksaan tagihan terlalu dekat pergantian hari. Coba setelah tengah malam.');

                return DB::transaction(function () use ($store, $engine, $product, $phone, $destination, $reference, $requestKey, $hash, $amount, $cost, $expiry, $data, $quote, $candidates, $position) {
                    $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
                    $engine->ensure($locked->sandbox === $store->sandbox, 'Mode toko berubah. Periksa tagihan kembali.');

                    return CustomerInquiry::create(['store_id' => $store->id, 'product_id' => $product->id, 'phone' => $phone, 'destination' => $destination, 'reference' => $reference, 'request_key' => $requestKey, 'request_hash' => $hash, 'amount' => $amount, 'cost' => $cost, 'expires_at' => $expiry,
                        'data' => ['sandbox' => $store->sandbox, 'customer_name' => mb_substr((string) ($data['customer_name'] ?? ''), 0, 150), 'period' => mb_substr((string) ($data['periode'] ?? ''), 0, 100), 'admin' => $quote['price'], 'candidates' => array_slice($candidates, $position)]]);
                });
            }
            $engine->ensure(false, 'Tagihan belum dapat diperiksa. Pastikan nomor tujuan benar dan jenis tagihan mendukung format standar.');
        });
    }

    public function present(CustomerInquiry $inquiry): array
    {
        return [...$inquiry->only(['id', 'destination', 'amount', 'expires_at']), 'customer_name' => $inquiry->data['customer_name'], 'period' => $inquiry->data['period']];
    }
}
