<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\PremiumStock;
use App\Models\Store;
use App\Models\User;
use App\Services\PlatformBackup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformBackupMysqlTest extends TestCase
{
    public function test_encrypted_database_media_and_whatsapp_restore_into_isolated_schema(): void
    {
        if (getenv('DIGITALTOKO_MYSQL_TEST') !== '1') {
            $this->markTestSkipped('Opt-in isolated MySQL integration test.');
        }
        $original = config('database.connections.mysql');
        $suffix = str_replace('-', '', (string) Str::uuid());
        $name = 'dt_backup_test_'.$suffix;
        $restored = 'dt_restore_'.$suffix;
        $root = storage_path('framework/testing/backup-mysql-'.$suffix);
        File::makeDirectory($root, 0700, true);
        config(['database.connections.backup_admin' => [...$original, 'database' => 'information_schema', 'url' => null]]);
        DB::connection('backup_admin')->statement('CREATE DATABASE `'.$name.'`');
        try {
            config(['database.default' => 'mysql', 'database.connections.mysql' => [...$original, 'database' => $name, 'url' => null], 'backup.key' => 'base64:'.base64_encode(random_bytes(32)), 'backup.directory' => $root.'/archives', 'backup.replica' => $root.'/replica', 'backup.keep' => 2, 'backup.mysqldump' => getenv('BACKUP_MYSQLDUMP') ?: config('backup.mysqldump'), 'backup.mysql' => getenv('BACKUP_MYSQL') ?: config('backup.mysql'), 'backup.sources' => ['private' => $root.'/private', 'public' => $root.'/public', 'whatsapp' => $root.'/wa']]);
            DB::purge('mysql');
            Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
            $owner = User::factory()->create(['name' => 'Backup fixture only']);
            $store = Store::create(['owner_id' => $owner->id, 'name' => 'Backup fixture', 'slug' => 'backup-fixture', 'template' => 'aurora', 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active']);
            $product = CatalogProduct::create(['store_id' => $store->id, 'code' => 'BACKUP', 'name' => 'Fixture only', 'kind' => 'premium', 'service' => 'Apps', 'category' => 'Test', 'sale_price' => 15000, 'active' => true]);
            PremiumStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'payload' => 'BACKUP ENCRYPTED STOCK', 'cost' => 1000]);
            File::ensureDirectoryExists($root.'/private/backups');
            File::ensureDirectoryExists($root.'/wa');
            File::ensureDirectoryExists($root.'/public');
            file_put_contents($root.'/private/test.txt', 'PRIVATE FIXTURE');
            file_put_contents($root.'/private/backups/excluded', 'DO NOT RECURSE');
            file_put_contents($root.'/public/logo.svg', 'PUBLIC FIXTURE');
            file_put_contents($root.'/wa/creds.json', 'FAKE WA SESSION');
            file_put_contents($root.'/wa/.worker.lock', 'IGNORE LOCK');
            $service = app(PlatformBackup::class);
            $first = $service->create();
            $manifest = $service->verify($first);
            $this->assertCount(4, $manifest['files']);
            $this->assertFileExists($root.'/replica/'.basename($first));
            $this->assertSame('ok', $service->health()['status']);
            $this->assertSame(0, Artisan::call('digitaltoko:backup-restore', ['file' => $first, '--database' => $restored, '--directory' => $root.'/restored']));
            config(['database.connections.restored' => [...$original, 'database' => $restored, 'url' => null]]);
            $this->assertSame('Backup fixture only', DB::connection('restored')->table('users')->where('id', $owner->id)->value('name'));
            $this->assertSame('PRIVATE FIXTURE', file_get_contents($root.'/restored/files/private/test.txt'));
            $this->assertSame('FAKE WA SESSION', file_get_contents($root.'/restored/files/whatsapp/creds.json'));
            $this->assertStringContainsString(config('app.key'), file_get_contents($root.'/restored/.env.recovery'));
            $this->assertFileDoesNotExist($root.'/restored/files/whatsapp/.worker.lock');
            $this->assertSame(1, DB::table('users')->count());
            $drill = $service->drill($first);
            $this->assertTrue($drill['encrypted_stock_checked']);
            $this->assertSame(1, $drill['tables']['stores']);
            $this->assertSame([], glob($root.'/archives/.drill-*'));
            $this->assertNotNull(json_decode(file_get_contents($root.'/archives/status.json'), true)['last_restore_verified_at']);

            $this->travel(1)->seconds();
            $service->create();
            $this->travel(1)->seconds();
            $service->create();
            $this->assertCount(2, glob($root.'/archives/digitaltoko-*.zip'));
            $this->assertCount(2, glob($root.'/replica/digitaltoko-*.zip'));
            $this->assertFileDoesNotExist($first);
            $this->assertSame(1, DB::connection('restored')->table('users')->count());
        } finally {
            DB::disconnect('mysql');
            DB::disconnect('restored');
            foreach ([$name, $restored] as $schema) {
                if (preg_match('/^dt_(backup_test|restore)_[a-f0-9]{32}$/D', $schema)) {
                    DB::connection('backup_admin')->statement('DROP DATABASE IF EXISTS `'.$schema.'`');
                }
            }
            $absolute = realpath($root);
            if ($absolute && dirname($absolute) === realpath(storage_path('framework/testing')) && str_starts_with(basename($absolute), 'backup-mysql-')) {
                File::deleteDirectory($absolute);
            }
            config(['database.default' => 'sqlite', 'database.connections.mysql' => $original]);
            DB::purge('mysql');
        }
    }
}
