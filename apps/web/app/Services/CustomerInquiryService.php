<?php

namespace App\Services;

use App\Models\CustomerInquiry;
use App\Models\CustomerOrder;
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

        return Cache::lock('inquiry:'.$hash, 45)->block(3, function () use ($engine, $store, $productId, $destination, $phone, $hash, $requestKey) {
            $old = CustomerInquiry::where('request_key', $requestKey)->first();
            if ($old) {
                $engine->ensure(hash_equals($old->request_hash, $hash) && $old->expires_at->isFuture(), 'Referensi cek tagihan sudah dipakai atau kedaluwarsa. Periksa tagihan kembali.');

                return $old;
            }
            $reusable = CustomerInquiry::where('store_id', $store->id)->where('request_hash', $hash)->where('expires_at', '>', now())
                ->whereNotIn('id', CustomerOrder::whereNotNull('inquiry_id')->select('inquiry_id'));
            $pending = (clone $reusable)->where('status', 'pending')->latest()->first();
            if ($pending) {
                return $pending;
            }
            $product = app(StoreCatalog::class)->query($store)->whereKey($productId)->firstOrFail();
            $quote = app(StoreCatalog::class)->present($product, $store, true);
            $fingerprint = hash('sha256', json_encode([$quote['price'], $quote['rule'], $quote['candidates']]));
            $cached = (clone $reusable)->where('status', 'ready')->latest()->orderByDesc('id')->first();
            if ($cached && ($cached->data['pricing_fingerprint'] ?? '') === $fingerprint) {
                return $cached;
            }
            $engine->ensure($product->kind === 'postpaid' && $quote['available'], 'Produk tagihan tidak tersedia.');
            $candidates = array_values(array_filter($quote['candidates'], fn ($row) => in_array($row['source'], ['digiflazz', 'otomax'], true)));
            $engine->ensure(count($candidates) > 0, 'Siapkan sumber pascabayar dan pasangan kode cek/bayar terlebih dahulu.');
            foreach ($candidates as $position => $candidate) {
                $mapping = ProductMapping::with('item.connection')->findOrFail($candidate['mapping_id']);

                $reference = 'DT'.Str::ulid();
                $expiry = now()->addMinutes(5)->min(now()->timezone('Asia/Jakarta')->endOfDay()->subSeconds(30)->utc());
                $engine->ensure($expiry->gt(now()->addSeconds(30)), 'Pemeriksaan terlalu dekat pergantian hari. Coba setelah tengah malam.');
                if ($candidate['source'] === 'otomax') {
                    return app(OtomaxInquiries::class)->start($store, $mapping, $candidate, ['store_id' => $store->id, 'product_id' => $product->id, 'phone' => $phone,
                        'destination' => $destination, 'reference' => $reference, 'request_key' => $requestKey, 'request_hash' => $hash, 'expires_at' => $expiry], max(0, $quote['price'] - $candidate['cost'] - ($candidate['commission'] ?? 0)), ['auto_update' => $quote['rule']['auto_update'], 'markup_rule' => $quote['rule']['markup'], 'fingerprint' => $fingerprint, 'fixed_fee' => $quote['rule']['auto_update'] ? null : $quote['price'], 'basis' => $quote['rule']['pricing_basis']]);
                }
                $credentials = app(StoreEnvironment::class)->credentials($mapping->item->connection);
                try {
                    $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20)->post('https://api.digiflazz.com/v1/transaction', [
                        'testing' => $store->sandbox, 'commands' => 'inq-pasca', 'username' => $credentials['username'], 'buyer_sku_code' => $mapping->item->sku, 'customer_no' => $destination, 'ref_id' => $reference, 'sign' => md5($credentials['username'].$credentials['api_key'].$reference)]);
                    $data = $response->json('data');
                } catch (\Throwable) {
                    continue;
                }
                if (! $response->successful() || ! is_array($data) || ($data['ref_id'] ?? '') !== $reference || ($data['customer_no'] ?? '') !== $destination || ! CustomerFulfillment::matchesDigiflazzSku($data['buyer_sku_code'] ?? null, $mapping->item->sku) || ($data['status'] ?? '') !== 'Sukses' || ($data['rc'] ?? '') !== '00') {
                    continue;
                }
                $cost = $data['price'] ?? null;
                $engine->ensure(is_int($cost) && $cost > 0 && $cost <= 100000000, 'Nominal tagihan pemasok tidak valid.');
                $engine->ensure(is_int($data['selling_price'] ?? null) && (! isset($data['admin']) || is_int($data['admin'])), 'Rincian harga jual/admin tagihan pemasok tidak valid.');
                $values = app(PostpaidBilling::class)->amounts($cost, (int) ($data['selling_price'] ?? 0), (int) ($data['admin'] ?? $candidate['cost']),
                    $quote['rule']['auto_update'] ? app(PostpaidBilling::class)->storeFee(max(0, (int) $data['selling_price'] - $cost), $quote['rule']['markup']) : max(0, $quote['price'] - $candidate['cost'] - ($candidate['commission'] ?? 0)), (string) ($data['customer_name'] ?? ''), (string) ($data['periode'] ?? ''));
                $values = app(PostpaidBilling::class)->priceFromSources($values, $quote['candidates'], $candidate['mapping_id'], $quote['rule']['markup'], $quote['rule']['auto_update'] ? null : $quote['price'], $quote['rule']['pricing_basis']);
                $amount = $values['amount'];
                $candidate['cost'] = $cost;
                $candidate['commission'] = -$values['commission'];
                $engine->ensure($amount >= $cost && $amount <= 100000000, 'Harga jual tagihan di bawah modal.');

                return DB::transaction(function () use ($store, $engine, $product, $phone, $destination, $reference, $requestKey, $hash, $amount, $cost, $expiry, $candidate, $values, $fingerprint) {
                    $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
                    StoreRetention::guard($locked);
                    $engine->ensure($locked->sandbox === $store->sandbox, 'Mode toko berubah. Periksa tagihan kembali.');

                    return CustomerInquiry::create(['status' => 'ready', 'store_id' => $store->id, 'product_id' => $product->id, 'phone' => $phone, 'destination' => $destination, 'reference' => $reference, 'request_key' => $requestKey, 'request_hash' => $hash, 'amount' => $amount, 'cost' => $cost, 'expires_at' => $expiry,
                        'data' => [...$values, 'pricing_fingerprint' => $fingerprint, 'sandbox' => $store->sandbox, 'candidates' => [$candidate]]]);
                });
            }
            $engine->ensure(false, 'Tagihan belum dapat diperiksa. Pastikan nomor tujuan benar dan jenis tagihan mendukung format standar.');
        });
    }

    public function present(CustomerInquiry $inquiry): array
    {
        $data = $inquiry->data;
        $message = match ($inquiry->status) {
            'ready' => 'Tagihan berhasil diperiksa. Periksa rincian sebelum membayar.',
            'failed' => 'Pemeriksaan tagihan gagal. Hubungi toko untuk bantuan.',
            default => match (true) {
                ! empty($data['transport_uncertain']) => 'Permintaan cek belum memperoleh respons pasti. Menunggu laporan supplier; jangan membuat pesanan pembayaran baru.',
                ! empty($data['parser_error']) || ! empty($data['parser_incomplete']) => 'Jawaban supplier belum dapat dibaca. Toko perlu memeriksa penangkap jawaban.',
                default => 'Tagihan sedang diperiksa supplier. Hasil diperbarui otomatis.',
            },
        };
        if ($inquiry->status === 'failed') {
            $reason = mb_strtolower((string) ($data['failure_reason'] ?? ''));
            if ($reason === '' && is_string($data['supplier_message'] ?? null)) {
                $captured = app(OtomaxParser::class)->matchRule(OtomaxParser::failedBillExample(), $data['supplier_message']);
                if ($captured['matched'] && ($captured['fields']['refid'] ?? '') === $inquiry->reference) {
                    $reason = mb_strtolower($captured['fields']['keterangan'] ?? '');
                }
            }
            if (preg_match('/(?:nomor|no\.?|id) (?:tujuan|pelanggan|meter(?:an)?) (?:salah|tidak valid|tidak ditemukan)/u', $reason)) {
                $message = 'Nomor tujuan salah atau tidak ditemukan. Periksa ID pelanggan / nomor meteran.';
            } elseif (str_contains($reason, 'sudah lunas') || str_contains($reason, 'sudah dibayar') || str_contains($reason, 'sudah terbayar')) {
                $message = 'Tagihan sudah dibayar / lunas.';
            } elseif (str_contains($reason, 'belum ada tagihan') || str_contains($reason, 'tidak ada tagihan')) {
                $message = 'Belum ada tagihan untuk nomor pelanggan ini.';
            }
        }

        $description = trim(mb_substr((string) ($data['description'] ?? $data['failure_reason'] ?? ''), 0, 500));
        $description = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $description);

        $billing = $inquiry->status === 'ready' ? app(PostpaidBilling::class)->publicBreakdown($data) : null;

        return [...$inquiry->only(['id', 'destination', 'amount', 'expires_at', 'status']), 'customer_name' => $data['customer_name'], 'period' => $data['period'],
            'billing' => $billing, 'message' => $message, 'description' => $description, 'bill' => $inquiry->status === 'ready' ? ($data['bill'] ?? null) : null, 'admin' => $inquiry->status === 'ready' ? ($data['supplier_admin'] ?? $data['admin'] ?? null) : null];
    }
}
