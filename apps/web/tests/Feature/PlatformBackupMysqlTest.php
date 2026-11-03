<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\PremiumStock;
use App\Models\Store;
use App\Models\User;
use App\Services\BackupRecovery;
use App\Services\BackupRequests;
use App\Services\LiveBackupRestore;
use App\Services\PlatformBackup;
use App\Services\RecoveryPreview;
use App\Services\RecoveryProcesses;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
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
        $panelRestored = null;
        $liveRestored = null;
        try {
            config(['database.default' => 'mysql', 'database.connections.mysql' => [...$original, 'database' => $name, 'url' => null], 'backup.key' => 'base64:'.base64_encode(random_bytes(32)), 'backup.directory' => $root.'/archives', 'backup.replica' => $root.'/replica', 'backup.keep' => 2, 'backup.restore_lock' => $root.'/live.lock', 'backup.restore_hold' => $root.'/hold.json', 'backup.mysqldump' => getenv('BACKUP_MYSQLDUMP') ?: config('backup.mysqldump'), 'backup.mysql' => getenv('BACKUP_MYSQL') ?: config('backup.mysql'), 'backup.sources' => ['private' => $root.'/private', 'public' => $root.'/public', 'whatsapp' => $root.'/wa']]);
            DB::purge('mysql');
            Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
            $owner = User::factory()->create(['name' => 'Backup fixture only']);
            $store = Store::create(['owner_id' => $owner->id, 'name' => 'Backup fixture', 'slug' => 'backup-fixture', 'template' => 'aurora', 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active']);
            $customer = Customer::create(['store_id' => $store->id, 'name' => 'Recovered Customer', 'phone' => '628000000001', 'balance' => 12000]);
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
            config(['backup.enabled' => true]);
            app(BackupRequests::class)->request($owner->id);
            $this->assertSame('queued', app(BackupRequests::class)->status()['state']);
            $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'backups', '--queue' => 'backups', '--once' => true, '--sleep' => 0, '--tries' => 1]));
            $this->assertSame('completed', app(BackupRequests::class)->status()['state']);
            $this->assertSame(0, DB::table('jobs')->where('queue', 'backups')->count());
            $latest = app(BackupRecovery::class)->snapshots()[0]['name'];
            app(BackupRecovery::class)->request($owner->id, $latest, null);
            $row = DB::table('system_health_signals')->where('group', 'backup_restores')->first();
            $operation = json_decode($row->details, true);
            $panelRestored = $operation['database'];
            $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'backups', '--queue' => 'backups', '--once' => true, '--sleep' => 0, '--tries' => 1]));
            $this->assertSame('completed', DB::table('system_health_signals')->where('name', $row->name)->value('status'));
            config(['database.connections.panel_recovery' => [...$original, 'database' => $panelRestored, 'url' => null]]);
            $this->assertSame('Backup fixture only', DB::connection('panel_recovery')->table('users')->where('id', $owner->id)->value('name'));
            $this->assertSame('PRIVATE FIXTURE', file_get_contents($root.'/archives/recoveries/'.$operation['id'].'/files/private/test.txt'));
            $this->assertSame(1, DB::table('users')->count());
            $this->assertSame([], glob($root.'/archives/.restore-input-*'));
            $this->assertSame(1, DB::connection('panel_recovery')->table('customers')->count());
            $router = app(RecoveryPreview::class)->prepare($operation['id'], 8011);
            $settings = json_decode(file_get_contents(dirname($router).'/settings.json'), true)['env'];
            $code = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {Illuminate\Support\Facades\Http::get('https://supplier.invalid/test');exit(3);}catch(Illuminate\Http\Client\StrayRequestException){echo "NETWORK_BLOCKED\n";}
if(config('mail.default')!=='array'||config('queue.connections.recovery_preview.driver')!=='null'){exit(4);}
try {Illuminate\Support\Facades\DB::table('customers')->update(['balance'=>999]);exit(2);}catch(Illuminate\Database\QueryException $e){if((int)($e->errorInfo[1]??0)!==1792){throw $e;}echo "READONLY_BLOCKED\n";}
PHP;
            $preview = new Process([PHP_BINARY, '-r', $code], base_path(), $settings);
            $preview->setTimeout(20)->run();
            $this->assertTrue($preview->isSuccessful(), $preview->getOutput().$preview->getErrorOutput());
            $this->assertStringContainsString('NETWORK_BLOCKED', $preview->getOutput());
            $this->assertStringContainsString('READONLY_BLOCKED', $preview->getOutput());

            $latest = app(BackupRecovery::class)->snapshots()[0]['name'];
            $customer->update(['balance' => 999]);
            $owner->update(['name' => 'CHANGED AFTER SNAPSHOT']);
            file_put_contents($root.'/private/test.txt', 'CHANGED AFTER SNAPSHOT');
            DB::statement('CREATE TABLE live_only_marker (id INT) ENGINE=InnoDB');
            config(['backup.live_enabled' => true]);
            $processes = $this->mock(RecoveryProcesses::class);
            $processes->shouldReceive('inspect')->once()->andReturn([]);
            $processes->shouldReceive('down')->once();
            $processes->shouldReceive('pause')->once();
            $processes->shouldReceive('up')->once();
            $processes->shouldNotReceive('resume');
            Str::createRandomStringsUsing(fn ($length) => str_repeat('0', $length));
            $liveId = app(BackupRecovery::class)->request($owner->id, $latest, null, 'active');
            $liveRestored = 'dt_restore_'.str_replace('-', '', $liveId);
            $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'backups', '--queue' => 'backups', '--once' => true, '--sleep' => 0, '--tries' => 1]));
            $journal = app(LiveBackupRestore::class)->read($liveId);
            $this->assertSame('completed', $journal['state']);
            $this->assertSame($name, DB::connection()->getDatabaseName());
            $this->assertSame('Backup fixture only', DB::table('users')->where('id', $owner->id)->value('name'));
            $this->assertSame(12000, (int) DB::table('customers')->where('id', $customer->id)->value('balance'));
            $this->assertFalse(DB::getSchemaBuilder()->hasTable('live_only_marker'));
            $this->assertSame('PRIVATE FIXTURE', file_get_contents($root.'/private/test.txt'));
            $this->assertFileExists($root.'/archives/'.$journal['before_backup']);
            $this->assertFileExists($root.'/hold.json');
            $this->assertSame(0, DB::table('jobs')->count());

        } finally {
            Str::createRandomStringsNormally();
            DB::disconnect('mysql');
            DB::disconnect('restored');
            DB::disconnect('panel_recovery');
            foreach (array_filter([$name, $restored, $panelRestored, $liveRestored]) as $schema) {
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
