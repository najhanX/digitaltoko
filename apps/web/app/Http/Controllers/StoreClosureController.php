<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerNotice;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerCommerce;
use App\Services\StoreObligations;
use App\Services\StoreRetention;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StoreClosureController extends Controller
{
    private function owner(Request $request, Store $store): void
    {
        $owner = $request->user('web');
        abort_unless($owner?->role === 'owner' && $owner->id === $store->owner_id && $owner->canUseOwnerFeatures(), 403);
    }

    public function show(Request $request, Store $store): Response
    {
        $this->owner($request, $store);
        $setting = app(CustomerCommerce::class)->settings($store);

        return Inertia::render('Owner/StoreClosure', [
            'store' => $store->only(['id', 'name', 'status']),
            'closing' => (bool) $setting->closing,
            'obligations' => app(StoreObligations::class)->inspect($store),
        ]);
    }

    public function save(Request $request, Store $store): RedirectResponse
    {
        $this->owner($request, $store);
        $setting = app(CustomerCommerce::class)->settings($store);
        $data = $request->validate(['closing' => 'required|boolean', 'finalize' => 'sometimes|boolean']);
        DB::transaction(function () use ($store, $setting, $data) {
            User::whereKey($store->owner_id)->lockForUpdate()->firstOrFail();
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            StoreRetention::guard($store);
            if ($data['finalize'] ?? false) {
                $owed = array_filter(app(StoreObligations::class)->inspect($store));
                app(CustomerCommerce::class)->ensure(! $owed, 'Tuntaskan saldo pelanggan, invoice, dan pesanan yang belum selesai terlebih dahulu.');
                $store->update(['status' => 'suspended_manual', 'closure_requested_at' => $store->closure_requested_at ?? now()]);
                $setting->update(['closing' => true]);
                app(StoreRetention::class)->review($store);
            } else {
                app(CustomerCommerce::class)->ensure($store->status !== 'suspended_manual', 'Permohonan penghapusan sudah diajukan. Hubungi administrator untuk tindak lanjut.');
                $previous = $setting->closing;
                $setting->update(['closing' => $data['closing']]);
                if (! $previous && $data['closing']) {
                    Customer::where('store_id', $store->id)->where('balance', '>', 0)->each(function ($customer) use ($store) {
                        CustomerNotice::firstOrCreate(['event' => 'closing:'.$store->id.':'.$customer->id.':'.now()->format('YmdHi')], ['store_id' => $store->id, 'phone' => $customer->phone, 'email' => $customer->email,
                            'message' => $store->name.' menghentikan transaksi baru. Saldo Anda Rp'.number_format($customer->balance, 0, ',', '.').'. Hubungi owner toko untuk penyelesaian saldo.']);
                    });
                }
            }
        });

        return back()->with('success', ($data['finalize'] ?? false) ? 'Permohonan penghapusan terkirim. Toko dihentikan dan menunggu persetujuan administrator.' : 'Status penutupan toko diperbarui.');
    }
}
