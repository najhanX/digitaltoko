<?php

namespace App\Services;

use App\Models\ModulePurchase;
use App\Models\Store;
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

    public function buy(Store $store): ModulePurchase
    {
        $invoice = DB::transaction(function () use ($store) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $setting = app(CustomerCommerce::class)->settings($store);
            app(CustomerCommerce::class)->ensure($store->acceptsOrders() && ! $setting->custom_licensed_at && $this->price() >= 1000, 'Pembelian modul belum tersedia atau sudah dimiliki toko.');

            return ModulePurchase::where('store_id', $store->id)->whereIn('status', ['pending', 'review'])->first() ?? ModulePurchase::create(['store_id' => $store->id, 'amount' => $this->price()]);
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
            $data = app(HanshopIdentity::class)->call('billing/invoice', ['invoice_id' => $invoice->id, 'id_user' => $store->owner->hanshop_user_id, 'amount' => $invoice->amount])['invoice'] ?? [];
            validator($data, ['invoice_id' => 'required|in:'.$invoice->id, 'id_user' => 'required|in:'.$store->owner->hanshop_user_id, 'amount' => 'required|integer|in:'.$invoice->amount,
                'total' => 'required|integer|min:'.$invoice->amount.'|max:'.($invoice->amount + 999), 'status' => 'required|in:PENDING,PAID,EXPIRED,REVIEW', 'qr_url' => 'nullable|url:https', 'expires_at' => 'required|date', 'paid_at' => 'nullable|date'])->validate();
            DB::transaction(function () use ($invoice, $store, $data) {
                Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
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
