<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\CommerceReceipt;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\CustomerOrder;
use App\Models\DiscountVoucher;
use App\Models\PremiumStock;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerCommerce;
use App\Services\Vouchers;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CustomerCommerceMysqlTest extends TestCase
{
    public function test_real_mysql_locks_prevent_double_debit_stock_delivery_and_payment_code_collision(): void
    {
        if (getenv('DIGITALTOKO_MYSQL_TEST') !== '1') {
            $this->markTestSkipped('Opt-in isolated MySQL integration test.');
        }
        $original = config('database.connections.mysql');
        $name = 'dt_commerce_test_'.str_replace('-', '', (string) Str::uuid());
        config(['database.connections.commerce_admin' => [...$original, 'database' => 'information_schema', 'url' => null]]);
        DB::connection('commerce_admin')->statement('CREATE DATABASE `'.$name.'`');
        try {
            $configuration = [...$original, 'database' => $name, 'url' => null];
            config(['database.default' => 'mysql', 'database.connections.mysql' => $configuration, 'queue.default' => 'database']);
            DB::purge('mysql');
            Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
            Http::preventStrayRequests();
            $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD90001']);
            $store = Store::create(['owner_id' => $owner->id, 'name' => 'MySQL Fixture', 'slug' => 'mysql-fixture', 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'template' => 'aurora', 'expires_at' => now()->addMonth()]);
            $customer = Customer::create(['store_id' => $store->id, 'phone' => '6281234567890', 'name' => 'Test', 'phone_verified_at' => now(), 'balance' => 100000]);
            $product = CatalogProduct::create(['store_id' => $store->id, 'code' => 'TEST', 'name' => 'Test', 'kind' => 'premium', 'service' => 'Aplikasi', 'category' => 'Demo', 'sale_price' => 15000, 'active' => true]);
            ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'stock', 'source_key' => 'stock', 'active' => true]);
            PremiumStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'cost' => 10000, 'payload' => 'TEST STOCK ONLY']);
            $input = ['purpose' => 'product', 'product_id' => $product->id, 'phone' => $customer->phone, 'destination' => $customer->phone, 'payment' => 'wallet', 'idempotency_key' => (string) Str::uuid()];
            $payload = ['database' => $name, 'config' => $configuration, 'store' => $store->id, 'customer' => $customer->id, 'input' => $input, 'mode' => 'begin'];
            $run = function (array $payloads) {
                $gate = storage_path('framework/testing/mysql-gate-'.Str::uuid());
                $processes = [];
                try {
                    foreach ($payloads as $payload) {
                        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/commerce-worker.php')], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key')]);
                        $process->setInput(json_encode([...$payload, 'gate' => $gate]))->setTimeout(45)->start();
                        $processes[] = $process;
                    }
                    $deadline = microtime(true) + 25;
                    while (count(glob($gate.'.ready.*')) < count($processes) && microtime(true) < $deadline) {
                        usleep(10000);
                    }
                    $this->assertCount(count($processes), glob($gate.'.ready.*'), 'All workers must reach the barrier before releasing requests.');
                    touch($gate);
                    foreach ($processes as $process) {
                        $process->wait();
                        $this->assertTrue($process->isSuccessful(), 'Isolated worker failed: '.$process->getErrorOutput());
                    }

                    return array_map(fn ($process) => trim($process->getOutput()), $processes);
                } finally {
                    foreach ($processes as $process) {
                        if ($process->isRunning()) {
                            $process->stop(1);
                        }
                    }
                    foreach (glob($gate.'*') as $file) {
                        unlink($file);
                    }
                }
            };
            $ids = $run([$payload, $payload]);
            $this->assertSame($ids[0], $ids[1]);
            $this->assertSame(85000, $customer->fresh()->balance);
            $this->assertSame(1, CustomerLedger::count());
            $second = app(CustomerCommerce::class)->begin($store, [...$input, 'idempotency_key' => (string) Str::uuid()], $customer);
            $run([[...$payload, 'mode' => 'fulfill', 'order' => $ids[0]], [...$payload, 'mode' => 'fulfill', 'order' => $second->id]]);
            $this->assertSame(1, PremiumStock::where('status', 'sent')->count());
            $this->assertSame(1, CustomerOrder::where('status', 'success')->count());
            $this->assertSame(1, CustomerOrder::where('status', 'failed')->count());
            $this->assertSame(85000, $customer->fresh()->balance);
            $this->assertSame(1, CustomerLedger::where('reason', 'refund')->count());
            $qris = '00020101021126080004TEST5204000053033605802ID5904TEST6007JAKARTA6304';
            $crc = 0xFFFF;
            for ($i = 0; $i < strlen($qris); $i++) {
                $crc ^= ord($qris[$i]) << 8;
                for ($j = 0; $j < 8; $j++) {
                    $crc = (($crc & 0x8000) ? ($crc << 1) ^ 0x1021 : $crc << 1) & 0xFFFF;
                }
            }$qris .= strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
            app(CustomerCommerce::class)->settings($store)->update(['gateway' => 'custom', 'custom_licensed_at' => now(), 'credentials' => ['qris_payload' => $qris, 'forward_token' => str_repeat('x', 64)]]);
            $topup = ['purpose' => 'topup', 'amount' => 50000, 'phone' => $customer->phone, 'destination' => $customer->phone, 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()];
            $ids = $run([[...$payload, 'input' => $topup], [...$payload, 'input' => [...$topup, 'idempotency_key' => (string) Str::uuid()]]]);
            $orders = CustomerOrder::whereIn('id', $ids)->get();
            $this->assertCount(2, $orders);
            $this->assertCount(2, $orders->pluck('total')->unique());
            $run([[...$payload, 'mode' => 'paid', 'order' => $ids[0]], [...$payload, 'mode' => 'paid', 'order' => $ids[0]]]);
            $this->assertSame(135000, $customer->fresh()->balance);
            $this->assertSame(1, CustomerLedger::where('reason', 'topup')->count());
            $this->assertSame(1, CommerceReceipt::count());
            $remoteOrder = CustomerOrder::where('status', 'failed')->firstOrFail();
            $remoteOrder->attempts()->create(['position' => 2, 'source' => 'digiflazz', 'reference' => 'DT'.Str::ulid(), 'status' => 'failed', 'cost' => 10000, 'last_checked_at' => now(), 'request_snapshot' => ['kind' => 'ppob']]);
            $customer->update(['balance' => 1000]);
            $success = ['status' => 'Sukses', 'rc' => '00', 'sn' => 'CONCURRENT-SN', 'price' => 10000];
            $callback = [...$payload, 'mode' => 'callback', 'order' => $remoteOrder->id, 'callback' => $success];
            $this->assertSame(['success', 'success'], $run([$callback, $callback]));
            $this->assertSame(-14000, $customer->fresh()->balance);
            $this->assertSame(1, CustomerLedger::where('reason', 'refund_reversal')->count());
            $failed = [...$callback, 'callback' => ['status' => 'Gagal', 'rc' => '02', 'price' => 10000]];
            $this->assertSame(['failed', 'failed'], $run([$failed, $failed]));
            $this->assertSame(1000, $customer->fresh()->balance);
            $this->assertSame(2, CustomerLedger::where('reason', 'refund')->count());

            $customer->update(['balance' => 100000]);
            for ($i = 0; $i < 2; $i++) {
                PremiumStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'cost' => 10000, 'payload' => 'VOUCHER TEST ONLY']);
            }
            $voucher = DiscountVoucher::factory()->create(['store_id' => $store->id, 'scope_key' => $store->id, 'usage_limit' => 1]);
            $voucherInput = [...$input, 'voucher_code' => $voucher->code, 'idempotency_key' => (string) Str::uuid()];
            $results = $run([[...$payload, 'mode' => 'voucher', 'input' => $voucherInput], [...$payload, 'mode' => 'voucher', 'input' => [...$voucherInput, 'idempotency_key' => (string) Str::uuid()]]]);
            $this->assertSame(1, count(array_filter($results, fn ($value) => $value === 'QUOTA_REJECTED')));
            $this->assertSame(1, app(Vouchers::class)->used($voucher));
            $this->assertSame(86000, $customer->fresh()->balance);

            $once = DiscountVoucher::factory()->create(['store_id' => $store->id, 'scope_key' => $store->id, 'once_per_user' => true]);
            $onceInput = [...$input, 'voucher_code' => $once->code, 'idempotency_key' => (string) Str::uuid()];
            $results = $run([[...$payload, 'mode' => 'voucher', 'input' => $onceInput], [...$payload, 'mode' => 'voucher', 'input' => [...$onceInput, 'idempotency_key' => (string) Str::uuid()]]]);
            $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'QUOTA_REJECTED')));
            $this->assertSame(1, app(Vouchers::class)->used($once));
            $different = Customer::create(['store_id' => $store->id, 'phone' => '628999999999', 'name' => 'Other', 'phone_verified_at' => now(), 'balance' => 100000]);
            $results = $run([[...$payload, 'mode' => 'voucher', 'input' => [...$onceInput, 'idempotency_key' => (string) Str::uuid()]], [...$payload, 'customer' => $different->id, 'mode' => 'voucher', 'input' => [...$onceInput, 'phone' => $different->phone, 'idempotency_key' => (string) Str::uuid()]]]);
            $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'QUOTA_REJECTED')));
            $this->assertSame(2, app(Vouchers::class)->used($once));

            $otherStore = Store::create(['owner_id' => $owner->id, 'name' => 'Other fixture', 'slug' => 'other-fixture', 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'template' => 'aurora', 'expires_at' => now()->addMonth()]);
            $platformVoucher = DiscountVoucher::factory()->create(['usage_limit' => 1]);
            $results = $run([[...$payload, 'mode' => 'platform_voucher', 'code' => $platformVoucher->code], [...$payload, 'store' => $otherStore->id, 'mode' => 'platform_voucher', 'code' => $platformVoucher->code]]);
            $this->assertSame(1, count(array_filter($results, fn ($value) => $value === 'QUOTA_REJECTED')));
            $this->assertSame(1, app(Vouchers::class)->used($platformVoucher));

            $perOwner = DiscountVoucher::factory()->create(['once_per_user' => true]);
            $results = $run([[...$payload, 'mode' => 'platform_voucher', 'code' => $perOwner->code], [...$payload, 'store' => $otherStore->id, 'mode' => 'platform_voucher', 'code' => $perOwner->code]]);
            $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'QUOTA_REJECTED')));
            $this->assertSame(1, app(Vouchers::class)->used($perOwner));
            $thirdOwner = User::factory()->create(['role' => 'owner']);
            $third = Store::create(['owner_id' => $thirdOwner->id, 'name' => 'Different owner', 'slug' => 'different-owner', 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'template' => 'aurora', 'expires_at' => now()->addMonth()]);
            $results = $run([[...$payload, 'mode' => 'platform_voucher', 'code' => $perOwner->code], [...$payload, 'store' => $third->id, 'mode' => 'platform_voucher', 'code' => $perOwner->code]]);
            $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'QUOTA_REJECTED')));
            $this->assertSame(2, app(Vouchers::class)->used($perOwner));

        } finally {
            DB::disconnect('mysql');
            $this->assertMatchesRegularExpression('/^dt_commerce_test_[a-f0-9]{32}$/', $name);
            DB::connection('commerce_admin')->statement('DROP DATABASE `'.$name.'`');
            DB::purge('commerce_admin');
            config(['database.connections.mysql' => $original, 'database.default' => 'sqlite']);
            DB::purge('mysql');
        }
    }
}
