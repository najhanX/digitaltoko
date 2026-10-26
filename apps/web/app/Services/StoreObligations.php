<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerInquiry;
use App\Models\CustomerOrder;
use App\Models\ModulePurchase;
use App\Models\ProvisioningCase;
use App\Models\Store;
use App\Models\SubscriptionInvoice;

class StoreObligations
{
    /** Simulated wallet credit is not real money owed. Debt must not disappear through deletion. */
    public function inspect(Store $store): array
    {
        $customers = Customer::where('store_id', $store->id);
        $orders = CustomerOrder::where('store_id', $store->id);

        return [
            'balance' => (int) (clone $customers)->where('balance', '>', 0)->sum('balance'),
            'debt' => abs((int) (clone $customers)->where('balance', '<', 0)->sum('balance')),
            'orders' => (clone $orders)->whereNotIn('status', ['success', 'failed', 'expired', 'cancelled'])->count(),
            'guests' => (clone $orders)->whereNull('customer_id')->where('status', 'failed')->where('payment_status', 'paid')->whereNull('resolution')->count(),
            'refunds' => (clone $orders)->whereNotNull('customer_id')->where('status', 'failed')->where('payment_status', 'paid')->whereNull('refunded_at')->count(),
            'inquiries' => CustomerInquiry::where('store_id', $store->id)->where('expires_at', '>', now())->whereNotIn('id', CustomerOrder::where('store_id', $store->id)->whereNotNull('inquiry_id')->select('inquiry_id'))->count(),
            'subscriptions' => SubscriptionInvoice::where('store_id', $store->id)->whereNotIn('status', ['applied', 'expired', 'cancelled', 'refunded'])->count(),
            'provisioning' => ProvisioningCase::where('store_id', $store->id)->whereIn('state', ['review', 'refund_requested', 'refund_processing'])->count(),
            'modules' => ModulePurchase::where('store_id', $store->id)->whereNotIn('status', ['paid', 'expired', 'cancelled'])->count(),
        ];
    }

    public function reasons(array $obligations): array
    {
        $labels = [
            'provisioning' => 'Penyiapan toko / pengembalian sewa belum selesai', 'balance' => 'Saldo pelanggan belum dikembalikan', 'debt' => 'Saldo pelanggan masih minus',
            'orders' => 'Pesanan atau pembayaran belum selesai', 'guests' => 'Penyelesaian pesanan guest belum dicatat',
            'refunds' => 'Refund pelanggan belum selesai', 'inquiries' => 'Pemeriksaan tagihan masih berlaku',
            'subscriptions' => 'Invoice sewa / jadwal paket belum selesai', 'modules' => 'Invoice modul belum selesai',
        ];

        return array_values(array_map(fn ($key) => $labels[$key], array_keys(array_filter($obligations))));
    }
}
