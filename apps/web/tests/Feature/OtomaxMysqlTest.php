<?php

namespace Tests\Feature;

use App\Models\OtomaxImport;
use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\OtomaxImports;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OtomaxMysqlTest extends TestCase
{
    public function test_large_import_uses_small_mysql_packets_in_an_isolated_database(): void
    {
        if (getenv('DIGITALTOKO_MYSQL_TEST') !== '1') {
            $this->markTestSkipped('Opt-in isolated MySQL integration test.');
        }
        $original = config('database.connections.mysql');
        $name = 'dt_otomax_test_'.str_replace('-', '', (string) Str::uuid());
        config(['database.connections.otomax_admin' => [...$original, 'database' => 'information_schema', 'url' => null]]);
        DB::connection('otomax_admin')->statement('CREATE DATABASE `'.$name.'`');
        try {
            config(['database.default' => 'mysql', 'database.connections.mysql' => [...$original, 'database' => $name, 'url' => null], 'suppliers.cache_store' => 'array']);
            DB::purge('mysql');
            Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
            Storage::fake('local');
            Http::preventStrayRequests();
            $store = Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved'])->id, 'name' => 'Import MySQL test', 'slug' => 'mysql-test', 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'template' => 'aurora', 'expires_at' => now()->addMonth()]);
            $connection = SupplierConnection::create(['store_id' => $store->id, 'kind' => 'otomax', 'slot' => Str::uuid(), 'name' => 'Fixture only', 'enabled' => true, 'credentials' => [], 'settings' => []]);
            $raw = 'otomax/'.$store->id.'/fixture.csv';
            Storage::disk('local')->put($raw, 'Fixture only');
            $record = OtomaxImport::create(['store_id' => $store->id, 'connection_id' => $connection->id, 'filename' => 'fixture.csv', 'file_hash' => hash('sha256', 'Fixture only'), 'payload' => ['path' => $raw], 'summary' => [], 'status' => 'draft', 'expires_at' => now()->addHour()]);
            $rows = [];
            for ($i = 0; $i < 6000; $i++) {
                $rows[] = ['sku' => 'TEST'.$i, 'name' => str_repeat('Product ', 17).$i, 'kind' => 'ppob', 'mode' => 'prepaid', 'service' => 'Produk digital', 'category' => 'Test', 'cost' => 1000, 'available' => true, 'quantity' => null];
            }
            $service = app(OtomaxImports::class);
            $service->savePreview($record, ['path' => $raw, 'sandbox' => false, 'connection_hash' => $service->fingerprint($connection), 'catalog_synced_at' => null], ['valid' => count($rows)], $rows);
            $payload = $record->fresh()->payload;
            $this->assertGreaterThan(1024 * 1024, Storage::disk('local')->size($payload['rows_path']));
            $this->assertLessThan(16384, strlen($record->fresh()->getRawOriginal('payload')));
            $record->refresh()->update(['status' => 'queued', 'payload' => [...$payload, 'missing' => 'disable']]);
            $service->apply($record->fresh());
            $this->assertSame('completed', $record->fresh()->status);
            $this->assertSame(6000, SupplierProduct::where('connection_id', $connection->id)->count());
            Storage::disk('local')->assertMissing($payload['rows_path']);
            Http::assertNothingSent();
        } finally {
            DB::disconnect('mysql');
            if (preg_match('/^dt_otomax_test_[a-f0-9]{32}$/D', $name)) {
                DB::connection('otomax_admin')->statement('DROP DATABASE `'.$name.'`');
            }
            config(['database.default' => 'sqlite', 'database.connections.mysql' => $original]);
            DB::purge('mysql');
            DB::purge('otomax_admin');
        }
    }
}
