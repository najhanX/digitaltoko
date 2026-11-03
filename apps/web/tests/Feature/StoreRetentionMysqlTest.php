<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\DiscountVoucher;
use App\Models\PlanOption;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreRetention;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StoreRetentionMysqlTest extends TestCase
{
    public function test_mysql_cascade_and_concurrent_renewal_cannot_erase_a_renewed_store(): void
    {
        if (getenv('DIGITALTOKO_MYSQL_TEST') !== '1') {
            $this->markTestSkipped('Opt-in isolated MySQL integration test.');
        }
        $original = config('database.connections.mysql');
        $name = 'dt_commerce_test_'.str_replace('-', '', (string) Str::uuid());
        $root = storage_path('framework/testing/retention-'.Str::uuid());
        File::makeDirectory($root, 0700, true);
        config(['database.connections.retention_admin' => [...$original, 'database' => 'information_schema', 'url' => null]]);
        DB::connection('retention_admin')->statement('CREATE DATABASE `'.$name.'`');
        try {
            $configuration = [...$original, 'database' => $name, 'url' => null];
            config(['database.default' => 'mysql', 'database.connections.mysql' => $configuration, 'queue.default' => 'database', 'platform.store_purge_enabled' => true, 'filesystems.disks.local.root' => $root, 'services.bot_manager.url' => 'http://127.0.0.1:3099', 'services.bot_manager.token' => str_repeat('x', 40)]);
            DB::purge('mysql');
            Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
            Http::preventStrayRequests();
            Http::fake(['http://127.0.0.1:3099/*' => Http::response(['status' => 'SUCCESS'])]);
            $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD90001', 'phone_verified_at' => now(), 'email_verified_at' => now()]);
            $newStore = fn ($slug) => Store::create(['owner_id' => $owner->id, 'name' => 'MySQL Retention', 'slug' => $slug, 'category' => 'web', 'plan' => 'orbit', 'status' => 'expired', 'template' => 'aurora', 'starts_at' => now()->subDays(60), 'expires_at' => now()->subDays(30)]);
            $store = $newStore('cascade');
            $customer = Customer::create(['store_id' => $store->id, 'name' => 'Fixture', 'phone' => '6281234567890']);
            CustomerOrder::create(['store_id' => $store->id, 'customer_id' => $customer->id, 'number' => 'DT'.Str::ulid(), 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'channel' => 'web', 'status' => 'success', 'payment_status' => 'paid', 'gateway' => 'wallet', 'phone' => $customer->phone, 'destination' => 'fixture', 'snapshot' => [], 'amount' => 10000, 'total' => 10000, 'expires_at' => now()->subDays(31)]);
            DB::table('customer_ledgers')->insert(['id' => (string) Str::uuid(), 'store_id' => $store->id, 'customer_id' => $customer->id, 'event' => 'settled', 'amount' => -10000, 'balance_after' => 0, 'reason' => 'settlement', 'created_at' => now(), 'updated_at' => now()]);
            $this->assertTrue(app(StoreRetention::class)->purge($store));
            $this->assertModelMissing($customer);
            $this->assertSame(0, CustomerOrder::count());
            $plan = PlanOption::create(['name' => 'MySQL Renew', 'category' => 'full', 'plan' => 'galaxy', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
            $voucher = DiscountVoucher::factory()->create(['type' => 'percent', 'value' => 10000]);
            $retained = $newStore('unrelated');
            for ($iteration = 0; $iteration < 3; $iteration++) {
                $race = $newStore('race-'.$iteration);
                $common = ['database' => $name, 'config' => $configuration, 'storage' => $root, 'store' => $race->id, 'plan' => $plan->id, 'code' => $voucher->code];
                $processes = [];
                foreach (['retention-renew', 'retention-purge'] as $mode) {
                    $process = new Process([PHP_BINARY, base_path('tests/Fixtures/commerce-worker.php')], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key')]);
                    $process->setInput(json_encode([...$common, 'mode' => $mode]))->setTimeout(30)->start();
                    $processes[] = $process;
                }
                foreach ($processes as $process) {
                    $process->wait();
                    $this->assertTrue($process->isSuccessful(), 'Isolated retention worker failed: '.$process->getErrorOutput());
                }
                $result = array_map(fn ($process) => trim($process->getOutput()), $processes);
                if ($result[0] === 'RENEWED') {
                    $this->assertSame('HELD', $result[1]);
                    $this->assertSame('active', $race->fresh()->status);
                    $this->assertTrue($race->fresh()->expires_at->isFuture());
                } else {
                    $this->assertSame(['UNAVAILABLE', 'PURGED'], $result);
                    $this->assertModelMissing($race);
                }
                $this->assertModelExists($retained);
                $this->assertModelExists($owner);
            }
        } finally {
            DB::disconnect('mysql');
            DB::connection('retention_admin')->statement('DROP DATABASE `'.$name.'`');
            DB::disconnect('retention_admin');
            $absolute = realpath($root);
            $parent = realpath(storage_path('framework/testing'));
            if ($absolute && $parent && dirname($absolute) === $parent && str_starts_with(basename($absolute), 'retention-')) {
                File::deleteDirectory($absolute);
            }
            config(['database.default' => 'sqlite', 'database.connections.mysql' => $original]);
            DB::purge('mysql');
        }
    }
}
