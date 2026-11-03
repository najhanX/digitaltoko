<?php

namespace Database\Seeders;

use App\Models\CatalogProduct;
use App\Models\CommerceReceipt;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\CustomerOrder;
use App\Models\OrderAttempt;
use App\Models\Store;
use App\Models\StorePaymentSetting;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DevelopmentActivitySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'development', 'testing')) {
            throw new RuntimeException('Aktivitas dummy hanya untuk development.');
        }
        $owner = User::where('email', 'demo-owner@example.test')->firstOrFail();
        $password = Hash::make(DemoStoreSeeder::PASSWORD);
        foreach (['demo-aurora', 'demo-nebula', 'demo-atlas', 'demo-bot'] as $index => $slug) {
            DB::transaction(function () use ($owner, $password, $index, $slug): void {
                $store = Store::where('slug', $slug)->firstOrFail();
                if ($store->owner_id !== $owner->id) {
                    throw new RuntimeException('Toko demo dimiliki owner lain.');
                }
                StorePaymentSetting::firstOrCreate(['store_id' => $store->id], ['sandbox' => true, 'allow_guest' => true]);
                for ($invoice = 1; $invoice <= 6; $invoice++) {
                    $created = now()->subDays(60 - $invoice * 7);
                    $paid = $invoice % 3 !== 0;
                    SubscriptionInvoice::firstOrCreate(['provider_id' => 'DEMO-LEASE-'.$slug.'-'.$invoice], [
                        'store_id' => $store->id, 'owner_id' => $owner->id, 'purpose' => $invoice === 1 ? 'initial' : 'renew',
                        'snapshot' => ['demo' => true, 'name' => 'Langganan Simulasi Development', 'category' => 'full', 'plan' => 'galaxy', 'price' => 50000, 'duration_days' => 30],
                        'status' => $paid ? 'applied' : 'expired', 'total' => 50000, 'expires_at' => $created->copy()->addDay(),
                        'paid_at' => $paid ? $created->copy()->addMinute() : null, 'applied_at' => $paid ? $created->copy()->addMinutes(2) : null,
                        'created_at' => $created, 'updated_at' => $created->copy()->addDay(),
                    ]);
                }

                $products = CatalogProduct::where('store_id', $store->id)->where('code', 'like', 'DEMO-%')->orderBy('code')->get();
                if ($products->isEmpty()) {
                    throw new RuntimeException('Jalankan CatalogDemoSeeder sebelum aktivitas dummy.');
                }
                $customers = [];
                for ($i = 1; $i <= 25; $i++) {
                    $phone = '628000'.str_pad((string) ($index * 100 + $i), 7, '0', STR_PAD_LEFT);
                    $customer = Customer::firstOrCreate(['store_id' => $store->id, 'phone' => $phone], [
                        'name' => 'Pelanggan Simulasi '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'email' => 'pelanggan'.$i.'@example.test',
                        'password' => $password, 'balance' => 0, 'sandbox_balance' => 100000,
                        'phone_verified_at' => now()->subDays(30), 'email_verified_at' => now()->subDays(30),
                    ]);
                    if ($customer->wasRecentlyCreated) {
                        CustomerLedger::create(['store_id' => $store->id, 'customer_id' => $customer->id, 'event' => 'demo:opening:'.$customer->id,
                            'reason' => 'topup', 'amount' => 100000, 'balance_after' => 100000, 'is_sandbox' => true, 'metadata' => ['demo' => true, 'description' => 'Saldo awal simulasi, bukan uang nyata.']]);
                    }
                    $customers[] = $customer;
                }
                for ($i = 1; $i <= 40; $i++) {
                    $product = $products[($i - 1) % $products->count()];
                    $status = ['success', 'failed', 'cancelled', 'expired'][($i - 1) % 4];
                    $paid = in_array($status, ['success', 'failed'], true);
                    $customer = $customers[($i - 1) % 25];
                    $createdAt = now()->subDays(41 - $i)->startOfHour()->addMinutes($i);
                    $number = 'DEMO'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).str_pad((string) $i, 5, '0', STR_PAD_LEFT);
                    $order = CustomerOrder::firstOrCreate(['store_id' => $store->id, 'idempotency_key' => 'development-order-'.$i], [
                        'number' => $number, 'request_hash' => hash('sha256', $number), 'customer_id' => $status === 'failed' ? null : $customer->id,
                        'product_id' => $product->id, 'channel' => $i % 2 === 0 ? 'bot' : 'web', 'purpose' => 'product', 'is_sandbox' => true,
                        'status' => $status, 'payment_status' => $paid ? 'paid' : ($status === 'expired' ? 'expired' : 'unpaid'), 'gateway' => 'sandbox',
                        'phone' => $customer->phone, 'email' => $customer->email, 'destination' => $product->kind === 'premium' ? $customer->phone : '080000000001',
                        'snapshot' => [...$product->only(['code', 'name', 'kind', 'service', 'category']), 'demo' => true, 'sandbox' => true, 'subtotal' => $product->sale_price],
                        'amount' => $product->sale_price, 'total' => $product->sale_price, 'expires_at' => $createdAt->copy()->addMinutes(15),
                        'paid_at' => $paid ? $createdAt->copy()->addMinute() : null, 'completed_at' => $createdAt->copy()->addMinutes(2),
                        'result' => $status === 'success' ? 'SIMULASI BERHASIL — bukan pengiriman produk nyata.' : null,
                        'message' => 'Data contoh development. Tidak ada pembayaran atau pengiriman supplier.',
                        'created_at' => $createdAt, 'updated_at' => $createdAt->copy()->addMinutes(2),
                    ]);
                    if (! $order->wasRecentlyCreated) {
                        continue;
                    }
                    if ($paid) {
                        CommerceReceipt::create(['order_id' => $order->id, 'event' => 'demo:receipt:'.$order->id, 'amount' => $order->total, 'paid_at' => $order->paid_at]);
                        OrderAttempt::create(['order_id' => $order->id, 'position' => 1, 'reference' => 'ATTEMPT-'.$number,
                            'source' => $product->kind === 'premium' ? 'stock' : 'digiflazz', 'cost' => max(0, $product->sale_price - 1000), 'status' => $status,
                            'request_snapshot' => ['demo' => true, 'testing' => true, 'supplier_response' => ['message' => 'SIMULASI '.$status.' — tidak dikirim ke supplier.', 'parser_error' => null]],
                            'result' => $order->result, 'message' => 'Riwayat percobaan simulasi development.', 'created_at' => $createdAt, 'updated_at' => $createdAt->copy()->addMinutes(2)]);
                    }
                }
            });
        }
    }
}
