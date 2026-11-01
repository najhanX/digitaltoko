<?php

namespace App\Services;

use App\Models\CustomerInquiry;
use App\Models\CustomerOrder;
use App\Models\OrderAttempt;
use App\Models\ProductMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostpaidFailover
{
    public function matches(CustomerOrder $order, array $values): bool
    {
        $expected = $order->snapshot['bill_breakdown'] ?? [];
        $normalize = static fn ($value) => mb_strtoupper(preg_replace('/\s+/u', '', (string) $value));
        $period = $this->period($order->snapshot['bill_period'] ?? '');

        return isset($expected['bill'], $expected['supplier_admin'])
            && $expected['bill'] === $values['bill'] && $expected['supplier_admin'] === $values['supplier_admin']
            && $normalize($order->snapshot['bill_customer'] ?? '') === $normalize($values['customer_name'])
            && $period !== null && $period === $this->period($values['period']);
    }

    private function period(string $value): ?string
    {
        if (trim($value) === '') {
            return '';
        }
        $parts = preg_split('/[,;|]|\r?\n/u', trim($value));
        $periods = [];
        foreach ($parts as $part) {
            $month = $this->month($part);
            if ($month === null || in_array($month, $periods, true)) {
                return null;
            }
            $periods[] = $month;
        }
        sort($periods, SORT_STRING);

        return implode(',', $periods);
    }

    private function month(string $value): ?string
    {
        $text = mb_strtoupper(preg_replace('/[\s\/-]+/u', '', $value));
        $months = ['JANUARI' => '01', 'JANUARY' => '01', 'FEBRUARI' => '02', 'FEBRUARY' => '02', 'MARET' => '03', 'MARCH' => '03', 'APRIL' => '04', 'MEI' => '05', 'MAY' => '05', 'JUNI' => '06', 'JUNE' => '06', 'JULI' => '07', 'JULY' => '07', 'AGUSTUS' => '08', 'AUGUST' => '08', 'SEPTEMBER' => '09', 'OKTOBER' => '10', 'OCTOBER' => '10', 'NOVEMBER' => '11', 'DESEMBER' => '12', 'DECEMBER' => '12'];
        foreach ($months as $name => $month) {
            if (preg_match('/^'.$name.'([0-9]{4})$/', $text, $match)) {
                return $match[1].$month;
            }
        }
        if (preg_match('/^(0[1-9]|1[0-2])([0-9]{4})$/', $text, $match)) {
            return $match[2].$match[1];
        }

        return preg_match('/^[0-9]{4}(0[1-9]|1[0-2])$/', $text) ? $text : null;
    }

    public function prepare(CustomerOrder $order, OrderAttempt $attempt): ?array
    {
        $data = $attempt->request_snapshot;
        if (empty($data['postpaid_preparing'])) {
            return null;
        }
        if (! $order->created_at->timezone('Asia/Jakarta')->isSameDay(now('Asia/Jakarta'))) {
            return ['status' => 'failed', 'message' => 'Sumber cadangan tidak dibayar karena hari tagihan berubah.'];
        }
        try {
            $inquiry = isset($data['fallback_inquiry_id'])
                ? CustomerInquiry::where('store_id', $order->store_id)->findOrFail($data['fallback_inquiry_id'])
                : app(CustomerInquiryService::class)->quote($order->store, $order->product_id, $order->destination, $order->phone, 'fallback:'.$attempt->id, $data['mapping_id']);
        } catch (ValidationException) {
            return ['status' => 'failed', 'message' => 'Sumber cadangan belum dapat memvalidasi tagihan.'];
        }
        DB::transaction(function () use ($inquiry, $order) {
            $locked = CustomerInquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            abort_if(isset($locked->data['failover_order_id']) && $locked->data['failover_order_id'] !== $order->id, 409);
            $locked->update(['data' => [...$locked->data, 'failover_order_id' => $order->id]]);
        });
        $inquiry->refresh();
        $data['fallback_inquiry_id'] = $inquiry->id;
        $attempt->update(['request_snapshot' => $data]);
        if ($inquiry->status === 'pending' && $inquiry->expires_at->isFuture()) {
            return ['status' => 'pending', 'message' => 'Menunggu hasil cek tagihan pemasok cadangan; pembayaran belum dikirim.'];
        }
        if ($inquiry->status !== 'ready' || $inquiry->expires_at->isPast() || ! $this->matches($order, $inquiry->data)) {
            return ['status' => 'failed', 'message' => 'Sumber cadangan dilewati: hasil cek gagal, kedaluwarsa, atau rincian tagihan berbeda.'];
        }
        if ($inquiry->cost > (int) ($order->snapshot['subtotal'] ?? $order->amount)) {
            return ['status' => 'failed', 'message' => 'Modal pemasok cadangan melebihi harga pesanan yang disetujui.'];
        }
        if ($attempt->source === 'otomax' && ($inquiry->data['otomax'] ?? null) !== ($data['otomax'] ?? null)) {
            return ['status' => 'failed', 'message' => 'Konfigurasi pemasok cadangan berubah; pembayaran tidak dikirim.'];
        }
        $mapping = ProductMapping::with('item.connection')->where('store_id', $order->store_id)->find($data['mapping_id']);
        $item = $mapping?->item;
        $connection = $item?->connection;
        if (! $mapping?->active || ! $item?->available || ($item->quantity !== null && $item->quantity <= 0) || ! $connection?->enabled || $connection->id !== $data['connection_id'] || $item->sku !== $data['sku']) {
            return ['status' => 'failed', 'message' => 'Parsing cadangan tidak lagi aktif atau tersedia.'];
        }
        $credentialsMatch = $attempt->source === 'otomax'
            ? app(OtomaxClient::class)->snapshot($connection) === $data['otomax']
            : app(StoreEnvironment::class)->credentials($connection) === $data['credentials'];
        if (! $credentialsMatch) {
            return ['status' => 'failed', 'message' => 'Koneksi pemasok cadangan berubah; pembayaran tidak dikirim.'];
        }
        $data['postpaid_preparing'] = false;
        $data['inquiry_reference'] = $inquiry->reference;
        $attempt->update(['cost' => $inquiry->cost, 'request_snapshot' => $data]);

        return null;
    }
}
