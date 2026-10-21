<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\OrderAttempt;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CustomerOrderDecision
{
    public function eligible(CustomerOrder $order): bool
    {
        return $order->purpose === 'product' && $order->payment_status === 'paid'
            && in_array($order->status, ['paid', 'processing', 'pending', 'review'], true)
            && $order->attempts()->whereNotNull('last_checked_at')->where('status', '!=', 'ready')->orderByDesc('position')->first()?->source === 'digiflazz';
    }

    public function decide(CustomerOrder $order, string $status, string $note, string $result, string $actor): void
    {
        $lock = Cache::lock('customer-fulfill:'.$order->id, 120);
        app(CustomerCommerce::class)->ensure($lock->get(), 'Pemasok sedang diperiksa. Tunggu sebentar lalu ulangi.');
        try {
            DB::transaction(function () use ($order, $status, $note, $result, $actor) {
                Store::whereKey($order->store_id)->lockForUpdate()->firstOrFail();
                $order = CustomerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                app(CustomerCommerce::class)->ensure($this->eligible($order), 'Hanya pesanan produk lunas yang telah dikirim ke Digiflazz dan belum selesai yang dapat diputuskan manual.');
                $attempt = $order->attempts()->whereNotNull('last_checked_at')->where('status', '!=', 'ready')->orderByDesc('position')->lockForUpdate()->firstOrFail();
                app(CustomerCommerce::class)->ensure($attempt->source === 'digiflazz', 'Sumber terakhir bukan Digiflazz. Periksa hasil sumber tersebut terlebih dahulu.');
                $snapshot = $order->snapshot;
                unset($snapshot['supplier_callback_hash'], $snapshot['manual_callback_hash']);
                $snapshot['manual_decision'] = ['attempt_id' => $attempt->id, 'actor' => $actor, 'note' => $note, 'status' => $status, 'at' => now()->toIso8601String()];
                $order->update(['snapshot' => $snapshot]);
                $this->apply($order, $attempt, $status, $result, 'Keputusan manual toko: '.$note, $actor);
            }, 3);
        } finally {
            $lock->release();
        }
    }

    /** Called only after callback authentication, under the fulfillment and database locks. */
    public function callback(CustomerOrder $order, OrderAttempt $attempt, array $data): void
    {
        $status = $data['status'] === 'Sukses' && $data['rc'] === '00' ? 'success' : ($data['status'] === 'Gagal' && CustomerFulfillment::isDefinitiveFailure($data['rc']) ? 'failed' : 'pending');
        $snapshot = $order->snapshot;
        $hash = hash('sha256', json_encode([$status, $data['rc'], $data['sn'] ?? '', $data['price'] ?? null]));
        if (($snapshot['supplier_callback_hash'] ?? $snapshot['manual_callback_hash'] ?? null) === $hash) {
            return;
        }
        $result = mb_substr((string) ($data['sn'] ?? ($status === 'success' ? ($order->result ?: 'Pesanan berhasil.') : '')), 0, 20000);
        if ($attempt->status === $status && ($attempt->result ?? '') === $result && $order->status === $status && ($order->result ?? '') === $result && ! ($status === 'success' && $order->refunded_at) && ! ($status === 'failed' && $order->customer_id && ! $order->refunded_at)) {
            return;
        }
        $snapshot['supplier_callback_hash'] = $hash;
        $snapshot['supplier_reconciled'] = true;
        $order->update(['snapshot' => $snapshot]);
        $cost = $data['price'] ?? null;
        $attempt->update(['status' => $status, 'result' => $result, 'message' => 'Hasil terbaru webhook Digiflazz.', ...(is_numeric($cost) && $cost >= 0 ? ['cost' => (int) $cost] : [])]);
        if ($status === 'success' && (! is_numeric($cost) || $cost < 0 || $cost > (int) ($order->snapshot['subtotal'] ?? $order->amount))) {
            $status = 'review';
        }
        $this->apply($order, $attempt, $status, $result, $status === 'review' ? 'Harga hasil supplier perlu diperiksa toko.' : 'Status diperbarui dari webhook Digiflazz.', 'webhook:digiflazz');
    }

    private function apply(CustomerOrder $order, OrderAttempt $attempt, string $status, string $result, string $message, string $actor): void
    {
        $snapshot = $order->snapshot;
        $revision = (int) ($snapshot['decision_revision'] ?? 0) + 1;
        $snapshot['decision_revision'] = $revision;
        $event = 'order:'.$order->id.':decision:'.$revision;
        $refundAt = $order->refunded_at;
        if ($order->customer_id) {
            $customer = Customer::whereKey($order->customer_id)->lockForUpdate()->firstOrFail();
            if ($status === 'failed' && ! $refundAt) {
                app(CustomerWallet::class)->apply($customer, $order->refund_amount, $event.':refund', 'refund', ['actor' => $actor, 'note' => $message], $order->is_sandbox);
                $refundAt = now();
            } elseif ($status === 'success' && $refundAt) {
                app(CustomerWallet::class)->reverseRefund($customer, $order->refund_amount, $event.':reversal', ['actor' => $actor, 'note' => $message], $order->is_sandbox);
                $refundAt = null;
            }
        }
        $snapshot['decision_history'][] = ['status' => $status, 'supplier_status' => $actor === 'webhook:digiflazz' ? $attempt->status : null, 'actor' => $actor, 'note' => $message, 'at' => now()->toIso8601String()];
        $order->update(['status' => $status, 'result' => $result, 'message' => $message, 'snapshot' => $snapshot, 'refunded_at' => $refundAt, 'completed_at' => in_array($status, ['success', 'failed'], true) ? now() : null]);
        app(CustomerCommerce::class)->notice($order, $status, 'decision:'.$revision);
    }
}
