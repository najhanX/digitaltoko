<?php

namespace App\Services;

use App\Models\CustomerInquiry;
use App\Models\OtomaxEvent;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\SupplierProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OtomaxInquiries
{
    public function start(Store $store, ProductMapping $mapping, array $candidate, array $attributes, int $markup, array $pricing = []): CustomerInquiry
    {
        $connection = $mapping->item->connection;
        $settings = $mapping->postpaid;
        $check = SupplierProduct::where('store_id', $store->id)->where('connection_id', $connection->id)->findOrFail($settings['inquiry_id']);
        abort_unless($check->available && $check->cost === 0 && (int) ($check->billing['adjustment'] ?? 0) === 0 && $check->id !== $mapping->item->id, 422, 'Pilih produk cek tagihan gratis yang tersedia pada supplier yang sama.');
        $snapshot = app(OtomaxClient::class)->snapshot($connection);
        $inquiry = CustomerInquiry::create([...$attributes, 'status' => 'pending', 'connection_id' => $connection->id, 'amount' => 0, 'cost' => 0,
            'data' => ['sandbox' => $store->sandbox, 'customer_name' => '', 'period' => '', 'admin' => $settings['admin'], 'candidates' => [$candidate],
                'otomax' => $snapshot, 'inquiry_sku' => $check->sku, 'payment_sku' => $mapping->item->sku,
                'fallback_candidates' => $pricing['fallback_candidates'] ?? [], 'pricing' => $pricing, 'pricing_fingerprint' => $pricing['fingerprint'] ?? null, 'adjustment' => (int) ($mapping->item->billing['adjustment'] ?? $mapping->item->cost), 'markup' => $markup]]);
        try {
            if ($store->sandbox && ! app(SupplierSimulator::class)->connection($connection)) {
                $parsed = ['status' => 'success', 'error' => null, 'fields' => ['refid' => $inquiry->reference, 'kode_produk' => $check->sku, 'tujuan' => $inquiry->destination,
                    'nama_pelanggan' => 'Pelanggan pengujian', 'tagihan' => '100000', 'admin' => (string) $settings['admin'], 'total_tagihan' => (string) (100000 + $settings['admin']), 'periode' => now()->format('Ym'), 'harga' => '0'], 'text' => 'SIMULASI CEK TAGIHAN'];
            } else {
                $parsed = app(OtomaxClient::class)->send($connection, 'transaction', ['product' => $check->sku, 'dest' => $inquiry->destination, 'refID' => $inquiry->reference, 'cost' => 0], $snapshot);
            }
            $this->apply($inquiry, $parsed);
            $updated = $inquiry->fresh();
            OtomaxEvent::create(['store_id' => $store->id, 'connection_id' => $connection->id, 'operation' => 'inquiry', 'reference' => $inquiry->reference,
                'status' => ! empty($parsed['error']) || ! empty($updated->data['parser_incomplete']) ? 'unmatched' : ($updated->status === 'ready' ? 'success' : $updated->status),
                'data' => ['message' => $parsed['text'] ?? '', 'parsed' => $parsed]]);
        } catch (ValidationException) {
            DB::transaction(function () use ($inquiry, $store, $connection): void {
                $locked = CustomerInquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'pending') {
                    return;
                }
                $locked->update(['data' => [...$locked->data, 'transport_uncertain' => true]]);
                OtomaxEvent::create(['store_id' => $store->id, 'connection_id' => $connection->id, 'operation' => 'inquiry', 'reference' => $inquiry->reference, 'status' => 'unknown',
                    'data' => ['message' => 'Permintaan cek tidak memperoleh respons pasti. Periksa koneksi/endpoint supplier dan tunggu callback. Permintaan tidak dikirim ulang otomatis.']]);
            });
        }

        return $inquiry->fresh();
    }

    public function apply(CustomerInquiry $inquiry, array $parsed): void
    {
        DB::transaction(function () use ($inquiry, $parsed): void {
            $inquiry = CustomerInquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            if ($inquiry->status !== 'pending' || $inquiry->expires_at->isPast()) {
                return;
            }
            $data = $inquiry->data;
            $fields = $parsed['fields'] ?? [];
            if (! isset($fields['sn']) && empty($parsed['error']) && preg_match('~^#([0-9]+)\s+([^\s.]+)\.([^\s]+) SUKSES\. SN/Ref: (.+)\. Saldo ~su', (string) ($parsed['text'] ?? ''), $standard)) {
                $fields += ['id_transaksi' => $standard[1], 'kode_produk' => $standard[2], 'tujuan' => $standard[3], 'sn' => $standard[4]];
            }
            if (isset($fields['refid']) && $fields['refid'] !== $inquiry->reference) {
                return;
            }
            if ((isset($fields['kode_produk']) && strcasecmp($fields['kode_produk'], $data['inquiry_sku']) !== 0) || (isset($fields['tujuan']) && $fields['tujuan'] !== $inquiry->destination)) {
                return;
            }
            $data['failure_reason'] = mb_substr((string) ($fields['keterangan'] ?? ''), 0, 500);
            $data['description'] = $data['failure_reason'];
            unset($data['transport_uncertain']);
            $data['parser_error'] = ! empty($parsed['error']);
            $data['supplier_message'] = mb_substr((string) ($parsed['text'] ?? $parsed['message'] ?? ''), 0, 20000);
            if (! empty($parsed['error']) || ! in_array($parsed['status'] ?? '', ['success', 'failed'], true)) {
                $inquiry->update(['data' => $data]);

                return;
            }
            if ($parsed['status'] === 'failed') {
                $inquiry->update(['status' => 'failed', 'data' => $data]);

                return;
            }
            try {
                $adjustment = $data['adjustment'];
                $markup = $data['markup'];
                if (! empty($data['pricing'])) {
                    $current = SupplierProduct::where('store_id', $inquiry->store_id)->where('connection_id', $inquiry->connection_id)->where('sku', $data['payment_sku'])->where('mode', 'prepaid')->first();
                    $adjustment = (int) ($current?->billing['adjustment'] ?? $adjustment);
                    if ($data['pricing']['auto_update'] ?? false) {
                        $markup = app(PostpaidBilling::class)->storeFee($adjustment, $data['pricing']['markup_rule']);
                    }
                }
                $values = app(PostpaidBilling::class)->otomax($fields, $adjustment, $markup);
                if (! empty($data['pricing'])) {
                    $catalog = app(StoreCatalog::class);
                    $store = Store::findOrFail($inquiry->store_id);
                    $product = $catalog->query($store, false)->findOrFail($inquiry->product_id);
                    $quote = $catalog->present($product, $store, true);
                    $values = app(PostpaidBilling::class)->priceFromSources($values, $quote['candidates'], $data['candidates'][0]['mapping_id'], $data['pricing']['markup_rule'], ($data['pricing']['auto_update'] ?? false) ? null : ($data['pricing']['fixed_fee'] ?? ($values['supplier_admin'] + $data['adjustment'] + $data['markup'])), $data['pricing']['basis'] ?? 'highest');
                }
                $data['adjustment'] = $adjustment;
            } catch (ValidationException) {
                $inquiry->update(['data' => [...$data, 'parser_incomplete' => true]]);

                return;
            }
            unset($data['parser_incomplete']);
            $candidate = $data['candidates'][0];
            $candidate['cost'] = $values['cost'];
            $candidate['commission'] = -$values['commission'];
            $inquiry->update(['status' => 'ready', 'amount' => $values['amount'], 'cost' => $values['cost'],
                'data' => [...$data, ...$values, 'candidates' => [$candidate]]]);
        });
    }
}
