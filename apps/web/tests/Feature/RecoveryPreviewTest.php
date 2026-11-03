<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\BackupRecovery;
use App\Services\CustomerCommerce;
use App\Services\RecoveryPreview;
use App\Services\SupplierCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecoveryPreviewTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/preview-test-'.Str::uuid());
        $this->id = (string) Str::uuid();
        config(['backup.directory' => $this->root.'/archives', 'backup.restore_hold' => $this->root.'/hold.json', 'backup.live_enabled' => false]);
        $folder = app(BackupRecovery::class)->destination($this->id);
        File::ensureDirectoryExists($folder.'/files/public', 0700);
        file_put_contents($folder.'/RESTORED.json', json_encode(['database' => 'dt_restore_'.str_replace('-', '', $this->id)]));
        file_put_contents($folder.'/.env.recovery', 'APP_KEY='.config('app.key')."\n");
        file_put_contents($folder.'/files/public/test.svg', '<svg/>');
    }

    protected function tearDown(): void
    {
        $path = realpath($this->root);
        if ($path && dirname($path) === realpath(storage_path('framework/testing')) && str_starts_with(basename($path), 'preview-test-')) {
            File::deleteDirectory($path);
        }
        parent::tearDown();
    }

    public function test_preview_uses_separate_storage_database_sessions_and_disables_external_channels(): void
    {
        $active = config('database.connections.mysql.database');
        $router = app(RecoveryPreview::class)->prepare($this->id, 8011);
        $settings = json_decode(file_get_contents(dirname($router).'/settings.json'), true)['env'];
        $this->assertSame('dt_restore_'.str_replace('-', '', $this->id), $settings['DB_DATABASE']);
        $this->assertSame($active, config('database.connections.mysql.database'));
        $this->assertSame('array', $settings['MAIL_MAILER']);
        $this->assertSame('null', $settings['QUEUE_CONNECTION']);
        $this->assertSame('true', $settings['RECOVERY_PREVIEW']);
        $this->assertSame('false', $settings['BACKUP_LIVE_RESTORE_ENABLED']);
        $this->assertNotSame(storage_path(), $settings['LARAVEL_STORAGE_PATH']);
        $this->assertFileExists($settings['LARAVEL_STORAGE_PATH'].'/app/public/test.svg');
        $this->assertNotSame(config('session.cookie'), $settings['SESSION_COOKIE']);
    }

    public function test_read_only_guard_blocks_mutations_webhooks_and_remote_host(): void
    {
        config(['backup.preview' => true, 'platform.domain' => 'localhost']);
        $this->post('/checkout')->assertStatus(423);
        $this->post('/api/commerce/digiflazz')->assertStatus(423);
        $this->get('/api/commerce/digiflazz')->assertStatus(423);
        $this->get('http://external.example.test/')->assertForbidden();
    }

    public function test_preview_rejects_main_ports_and_unfinished_restore(): void
    {
        foreach ([8000, 3099, 4000, 5173, 80] as $port) {
            try {
                app(RecoveryPreview::class)->prepare($this->id, $port);
                $this->fail('Main service port accepted');
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
        $this->expectExceptionMessage('belum lengkap');
        app(RecoveryPreview::class)->prepare((string) Str::uuid(), 8011);
    }

    public function test_read_helpers_do_not_create_settings_or_attempt_supplier_refresh(): void
    {
        $owner = User::factory()->create();
        $store = Store::create(['owner_id' => $owner->id, 'name' => 'Preview', 'slug' => 'preview-test']);
        config(['backup.preview' => true]);
        $setting = app(CustomerCommerce::class)->settings($store);
        $this->assertFalse($setting->exists);
        $this->assertDatabaseCount('store_payment_settings', 0);
        app(SupplierCatalog::class)->refreshForStore($store);
        $this->assertDatabaseCount('supplier_connections', 0);
    }
}
