<?php

namespace Tests\Feature;

use App\Jobs\RunPlatformBackup;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use App\Services\BackupRequests;
use App\Services\PlatformBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Inertia;
use Tests\TestCase;

class AdminBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false]);
        Queue::fake([RunPlatformBackup::class]);
        $this->partialMock(PlatformBackup::class, fn ($mock) => $mock->shouldReceive('configured')->andReturn(true));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function job(): RunPlatformBackup
    {
        $result = null;
        Queue::assertPushed(RunPlatformBackup::class, function ($job) use (&$result) {
            $result = $job;

            return true;
        });

        return $result;
    }

    public function test_only_admin_can_request_backups_and_duplicates_share_one_job(): void
    {
        $url = '/admin/system-health/backup';
        $this->post($url)->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(['role' => 'owner']))->post($url)->assertForbidden();
        $store = Store::create(['owner_id' => User::factory()->create()->id, 'name' => 'Staff test', 'slug' => 'staff-backup-test', 'template' => 'aurora', 'status' => 'active']);
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Staff', 'username' => 'backupstaff', 'email' => 'backupstaff@example.test', 'password' => 'test-password', 'permissions' => ['dashboard.view']]);
        auth('web')->logout();
        $this->actingAs($staff, 'staff')->post($url)->assertForbidden();
        auth('staff')->logout();
        $this->actingAs($this->admin(), 'web')->from('/admin/system-health')->post($url)->assertRedirect('/admin/system-health');
        $this->post($url)->assertRedirect();
        Queue::assertPushed(RunPlatformBackup::class, 1);
        $job = $this->job();
        $this->assertSame('backups', $job->connection);
        $this->assertSame('backups', $job->queue);
        $this->assertGreaterThan($job->timeout, config('queue.connections.backups.retry_after'));
        $this->assertSame('queued', app(BackupRequests::class)->status()['state']);
    }

    public function test_job_runs_once_and_does_not_rerun_after_completion(): void
    {
        $this->actingAs($this->admin(), 'web')->post('/admin/system-health/backup')->assertSessionHasNoErrors();
        $job = $this->job();
        $this->partialMock(PlatformBackup::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('create')->once()->andReturn('/private/backup.zip');
        });
        $job->handle(app(BackupRequests::class), app(PlatformBackup::class));
        $this->assertSame('completed', app(BackupRequests::class)->status()['state']);
        $job->handle(app(BackupRequests::class), app(PlatformBackup::class));
        $job->failed(new \RuntimeException('old failure'));
        $this->assertSame('completed', app(BackupRequests::class)->status()['state']);
    }

    public function test_job_failure_is_safe_and_retry_uses_new_request_id(): void
    {
        $this->actingAs($this->admin(), 'web')->post('/admin/system-health/backup');
        $old = $this->job();
        $this->partialMock(PlatformBackup::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('create')->once()->andThrow(new \RuntimeException('SECRET-DO-NOT-LEAK'));
            $mock->shouldReceive('failure')->once();
        });
        try {
            $old->handle(app(BackupRequests::class), app(PlatformBackup::class));
            $this->fail('Expected failure');
        } catch (\RuntimeException $error) {
            $this->assertStringNotContainsString('SECRET-DO-NOT-LEAK', $error->getMessage());
        }
        $this->assertSame('failed', app(BackupRequests::class)->status()['state']);
        app(BackupRequests::class)->request($this->admin()->id);
        $this->assertSame('queued', app(BackupRequests::class)->status()['state']);
        $old->failed(new \RuntimeException('old callback'));
        $this->assertSame('queued', app(BackupRequests::class)->status()['state']);
        $this->assertStringNotContainsString('SECRET-DO-NOT-LEAK', json_encode(app(BackupRequests::class)->status()));
    }

    public function test_expired_queued_request_is_not_executed_and_can_be_replaced(): void
    {
        app(BackupRequests::class)->request($this->admin()->id);
        $old = $this->job();
        $this->travel(16)->minutes();
        $this->assertSame('failed', app(BackupRequests::class)->status()['state']);
        $this->assertFalse(app(BackupRequests::class)->start($old->requestId));
        app(BackupRequests::class)->request($this->admin()->id);
        $this->assertFalse(app(BackupRequests::class)->start($old->requestId));
        $this->assertTrue(app(BackupRequests::class)->status()['busy']);
        Queue::assertPushed(RunPlatformBackup::class, 2);
    }

    public function test_unconfigured_backup_is_rejected_and_status_is_safe_for_partial_poll(): void
    {
        $this->partialMock(PlatformBackup::class, fn ($mock) => $mock->shouldReceive('configured')->andReturn(false));
        $this->actingAs($this->admin(), 'web')->post('/admin/system-health/backup')->assertSessionHasErrors('backup');
        Queue::assertNothingPushed();
        $this->get('/admin/system-health', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'Admin/SystemHealth', 'X-Inertia-Partial-Data' => 'backup'])->assertJsonPath('props.backup.state','idle')->assertJsonPath('props.backup.configured',false)->assertJsonMissingPath('props.items');
    }
}
