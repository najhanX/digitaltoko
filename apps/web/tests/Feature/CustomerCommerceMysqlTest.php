<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\CommerceReceipt;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\CustomerOrder;
use App\Models\PremiumStock;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerCommerce;
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
            config(['database.default' => 'mysql', 'database.connections.mysql' => $configuration]);
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
                $processes = [];
                foreach ($payloads as $payload) {
                    $process = new Process([PHP_BINARY, base_path('tests/Fixtures/commerce-worker.php')], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key')]);
                    $process->setInput(json_encode($payload))->setTimeout(45)->start();
                    $processes[] = $process;
                }foreach ($processes as $process) {
                    $process->wait();
                    $this->assertTrue($process->isSuccessful(), 'Isolated worker failed: '.$process->getErrorOutput());
                }

                return array_map(fn ($process) => trim($process->getOutput()), $processes);
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
