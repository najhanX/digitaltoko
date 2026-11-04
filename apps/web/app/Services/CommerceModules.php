<?php

namespace App\Services;

use App\Models\ModulePurchase;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Support\HanshopIdentity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CommerceModules
{
    public function price(): int
    {
        return (int) (DB::table('commerce_options')->where('id', 'custom_qris_price')->value('value') ?? 0);
    }

    public function buy(Store $store, ?string $voucherCode = null): ModulePurchase
    {
        $invoice = DB::transaction(function () use ($store, $voucherCode) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            StoreRetention::guard($store);
            $setting = app(CustomerCommerce::class)->settings($store);
            $preparing = $store->status === 'provisioning' && ! $store->admin_suspended_at && ! $store->is_trial && ! $store->trial_requested_at
                && SubscriptionInvoice::where('store_id', $store->id)->where('purpose', 'initial')->where('status', 'paid')->exists()
                && ! app(Provisioning::class)->held($store);
            app(CustomerCommerce::class)->ensure($store->acceptsOrders() || $preparing, 'Pembelian modul memerlukan sewa aktif atau pembayaran sewa awal yang sudah lunas dan sedang disiapkan. Jika toko ditangguhkan atau penyiapan ditinjau admin, selesaikan terlebih dahulu.');
            app(CustomerCommerce::class)->ensure(! $setting->custom_licensed_at, 'Modul QRIS custom sudah dimiliki toko ini.');
            app(CustomerCommerce::class)->ensure($this->price() >= 1000, 'Penjualan modul QRIS custom belum dibuka oleh administrator.');

            $open = ModulePurchase::where('store_id', $store->id)->whereIn('status', ['pending', 'review'])->first();
            if ($open) {
                app(CustomerCommerce::class)->ensure(Vouchers::code($open->snapshot['voucher']['code'] ?? '') === Vouchers::code($voucherCode), 'Selesaikan invoice modul yang masih berjalan.');

                return $open;
            }
            $voucher = app(Vouchers::class)->quote(null, $voucherCode, $this->price(), module: true, lock: true, owner: $store->owner);
            $invoice = ModulePurchase::create(['status' => 'pending', 'store_id' => $store->id, 'amount' => $voucher['total'] ?? $this->price(), 'snapshot' => $voucher ? ['subtotal' => $this->price(), 'voucher' => $voucher] : null]);
            app(Vouchers::class)->redeem($voucher, 'module', $invoice->id, owner: $store->owner);

            return $invoice;
        });
        $this->sync($invoice);

        return $invoice->fresh();
    }

    public function sync(ModulePurchase $invoice): void
    {
        if ($invoice->status !== 'pending') {
            return;
        }
        Cache::lock('module-sync:'.$invoice->id, 45)->get(function () use ($invoice) {
            $store = Store::with('owner')->findOrFail($invoice->store_id);
            $free = $invoice->amount === 0 && (int) ($invoice->snapshot['voucher']['discount'] ?? 0) > 0;
            $data = $free ? ['invoice_id' => $invoice->id, 'id_user' => $store->owner->hanshop_user_id, 'amount' => 0, 'total' => 0, 'status' => 'PAID', 'paid_at' => now()->toIso8601String(), 'expires_at' => now()->toIso8601String(), 'qr_url' => null] : app(HanshopIdentity::class)->call('billing/invoice', ['invoice_id' => $invoice->id, 'id_user' => $store->owner->hanshop_user_id, 'amount' => $invoice->amount])['invoice'] ?? [];
            validator($data, ['invoice_id' => 'required|in:'.$invoice->id, 'id_user' => 'required|in:'.$store->owner->hanshop_user_id, 'amount' => 'required|integer|in:'.$invoice->amount,
                'total' => 'required|integer|min:'.$invoice->amount.'|max:'.($invoice->amount + 999), 'status' => 'required|in:PENDING,PAID,EXPIRED,REVIEW', 'qr_url' => 'nullable|url:https', 'expires_at' => 'required|date', 'paid_at' => 'nullable|date'])->validate();
            DB::transaction(function () use ($invoice, $store, $data) {
                StoreRetention::guard(Store::whereKey($store->id)->lockForUpdate()->firstOrFail());
                $invoice = ModulePurchase::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                if ($invoice->status !== 'pending') {
                    return;
                }
                if ($data['status'] === 'PAID') {
                    app(CustomerCommerce::class)->ensure(! empty($data['paid_at']) && Carbon::parse($data['paid_at'])->lte(now()->addSeconds(30)) && Carbon::parse($data['paid_at'])->gte($invoice->created_at->copy()->subSeconds(30)), 'Waktu pembayaran modul belum valid.');
                }
                $invoice->update(['status' => strtolower($data['status']), 'total' => $data['total'], 'qr_url' => $data['qr_url'] ?? null, 'expires_at' => $data['expires_at'], 'paid_at' => $data['paid_at'] ?? null]);
                if ($invoice->status === 'paid') {
                    app(CustomerCommerce::class)->settings($store)->update(['custom_licensed_at' => $data['paid_at']]);
                }
            });
        });
    }
}
