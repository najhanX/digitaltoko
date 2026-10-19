<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerNotice;
use App\Models\CustomerOrder;
use App\Models\ModulePurchase;
use App\Models\Store;
use App\Services\CommerceModules;
use App\Services\CustomerCommerce;
use App\Services\CustomerFulfillment;
use App\Services\CustomerPayments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessCustomerCommerce extends Command
{
    protected $signature = 'digitaltoko:commerce {--limit=100}';

    protected $description = 'Rekonsiliasi pembayaran, proses pesanan pelanggan, serta kirim notifikasi tanpa memindahkan transaksi pending ke sumber lain.';

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $deadline = microtime(true) + 45;
        CustomerOrder::whereIn('status', ['awaiting_payment', 'paid', 'processing', 'pending'])->orderBy('updated_at')->limit($limit)->get()->each(function ($order) use ($deadline) {
            if (microtime(true) > $deadline) {
                return false;
            }
            try {
                if ($order->payment_status === 'unpaid' && $order->gateway === 'midtrans') {
                    app(CustomerPayments::class)->refresh($order);
                }
                $order->refresh();
                if ($order->payment_status === 'unpaid' && $order->expires_at->isPast()) {
                    DB::transaction(function () use ($order) {
                        Store::whereKey($order->store_id)->lockForUpdate()->first();
                        $locked = CustomerOrder::whereKey($order->id)->lockForUpdate()->first();
                        if ($locked->payment_status === 'unpaid' && $locked->status === 'awaiting_payment') {
                            $locked->update(['status' => 'expired', 'message' => 'Waktu pembayaran berakhir.']);
                        }
                    });
                }
                app(CustomerFulfillment::class)->run($order->fresh());
                $order->refresh();
                if (in_array($order->status, ['processing', 'pending'], true) && ! $order->pending_notified_at && $order->paid_at?->lte(now()->subMinutes(5))) {
                    DB::transaction(function () use ($order) {
                        $locked = CustomerOrder::whereKey($order->id)->lockForUpdate()->first();
                        if (! $locked->pending_notified_at && in_array($locked->status, ['processing', 'pending'], true)) {
                            $locked->update(['pending_notified_at' => now()]);
                            app(CustomerCommerce::class)->notice($locked, 'pending');
                        }
                    });
                }
                $order->touch();
            } catch (\Throwable $exception) {
                Log::warning('Customer commerce reconciliation postponed', ['order_id' => $order->id, 'error_type' => $exception::class]);
                $order->touch();
            }
        });
        CustomerOrder::whereNotNull('active_amount_key')->where('expires_at', '<', now()->subDay())->whereIn('status', ['success', 'failed', 'expired'])->update(['active_amount_key' => null]);
        ModulePurchase::where('status', 'pending')->oldest('updated_at')->limit($limit)->get()->each(function ($invoice) {
            try {
                app(CommerceModules::class)->sync($invoice);
            } catch (\Throwable) {
                $invoice->touch();
            }
        });
        Customer::where('balance', '>', 0)->with('store')->orderBy('updated_at')->limit($limit)->get()->each(function ($customer) {
            $store = $customer->store;
            if ($store && ! $store->acceptsOrders()) {
                CustomerNotice::firstOrCreate(['event' => 'stopped:'.$store->id.':'.$customer->id.':'.($store->expires_at?->timestamp ?? 0)], ['store_id' => $store->id, 'phone' => $customer->phone, 'email' => $customer->email, 'message' => $store->name.' sedang berhenti beroperasi. Saldo Anda Rp'.number_format($customer->balance, 0, ',', '.').'. Hubungi owner toko untuk penyelesaian saldo. Data saldo dan transaksi tetap disimpan.']);
            }
            $customer->touch();
        });
        $this->info('Antrean transaksi pelanggan diperiksa.');

        return self::SUCCESS;
    }
}
