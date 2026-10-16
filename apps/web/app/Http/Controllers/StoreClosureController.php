<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerNotice;
use App\Models\CustomerOrder;
use App\Models\Store;
use App\Services\CustomerCommerce;
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
        abort_unless($owner?->role === 'owner' && $owner->id === $store->owner_id && $owner->verification_status === 'approved', 403);
    }

    public function show(Request $request, Store $store): Response
    {
        $this->owner($request, $store);
        $setting = app(CustomerCommerce::class)->settings($store);

        return Inertia::render('Owner/StoreClosure', [
            'store' => $store->only(['id', 'name', 'status']),
            'closing' => (bool) $setting->closing,
            'obligations' => [
                'balance' => (int) Customer::where('store_id', $store->id)->where('balance', '>', 0)->sum('balance'),
                'orders' => CustomerOrder::where('store_id', $store->id)->whereIn('status', ['paid', 'processing', 'pending', 'review', 'awaiting_payment'])->count(),
                'guests' => CustomerOrder::where('store_id', $store->id)->whereNull('customer_id')->where('status', 'failed')->where('payment_status', 'paid')->whereNull('resolution')->count(),
            ],
        ]);
    }

    public function save(Request $request, Store $store): RedirectResponse
    {
        $this->owner($request, $store);
        $setting = app(CustomerCommerce::class)->settings($store);
        $data = $request->validate(['closing' => 'required|boolean', 'finalize' => 'sometimes|boolean']);
        DB::transaction(function () use ($store, $setting, $data) {
            $store = Store::whereKey($store->id)->lockForUpdate()->first();
            if ($data['finalize'] ?? false) {
                $owed = Customer::where('store_id', $store->id)->where('balance', '>', 0)->exists() || CustomerOrder::where('store_id', $store->id)->whereIn('status', ['paid', 'processing', 'pending', 'review', 'awaiting_payment'])->exists()
                 || CustomerOrder::where('store_id', $store->id)->whereNull('customer_id')->where('status', 'failed')->where('payment_status', 'paid')->whereNull('resolution')->exists();
                app(CustomerCommerce::class)->ensure(! $owed, 'Tuntaskan saldo pelanggan, invoice, dan pesanan yang belum selesai terlebih dahulu.');
                $store->update(['status' => 'suspended_manual']);
                $setting->update(['closing' => true]);
            } else {
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

        return back()->with('success', 'Status penutupan toko diperbarui.');
    }
}
