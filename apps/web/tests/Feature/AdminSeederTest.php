<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.admin' => ['name' => 'Administrator Test', 'email' => '  ADMIN@example.test ', 'password' => 'Local-admin-test-123!']]);
        Http::preventStrayRequests();
    }

    public function test_creates_only_admin_and_can_authenticate(): void
    {
        $this->seed(AdminSeeder::class);
        $this->assertDatabaseCount('users', 1);
        foreach (['stores', 'store_staff', 'customers', 'catalog_products', 'plan_options'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $admin = User::sole();
        $this->assertSame('admin', $admin->role);
        $this->assertSame('admin@example.test', $admin->email);
        $this->assertTrue(Hash::check('Local-admin-test-123!', $admin->password));
        $this->assertNotSame('Local-admin-test-123!', $admin->password);
        config(['platform.domain' => 'localhost']);
        $this->post('/admin/login', ['email' => 'admin@example.test', 'password' => 'Local-admin-test-123!'])->assertRedirect('/admin');
        Http::assertNothingSent();
    }

    public function test_rerun_updates_configured_admin_without_duplicates_or_rehash_when_unchanged(): void
    {
        $this->seed(AdminSeeder::class);
        $hash = User::sole()->password;
        $this->seed(AdminSeeder::class);
        $this->assertSame($hash, User::sole()->password);
        config(['platform.admin.name' => 'Nama Baru', 'platform.admin.password' => 'New-admin-password-123!']);
        $this->seed(AdminSeeder::class);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Nama Baru', User::sole()->name);
        $this->assertTrue(Hash::check('New-admin-password-123!', User::sole()->password));
    }

    public function test_rejects_missing_or_invalid_configuration_without_creating_account(): void
    {
        foreach ([['email' => ''], ['email' => 'invalid'], ['password' => ''], ['password' => 'short'], ['password' => str_repeat('x', 73)]] as $changes) {
            $original = config('platform.admin');
            config(['platform.admin' => [...$original, ...$changes]]);
            try {
                $this->seed(AdminSeeder::class);
                $this->fail('Invalid admin configuration accepted');
            } catch (ValidationException) {
                $this->assertDatabaseCount('users', 0);
            }
            config(['platform.admin' => $original]);
        }
    }

    public function test_does_not_promote_or_change_existing_owner_with_same_email(): void
    {
        $owner = User::factory()->create(['email' => 'admin@example.test', 'role' => 'owner']);
        $hash = $owner->password;
        try {
            $this->seed(AdminSeeder::class);
            $this->fail('Owner promoted to admin');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('non-admin', $error->getMessage());
        }
        $this->assertSame('owner', $owner->fresh()->role);
        $this->assertSame($hash, $owner->fresh()->password);
    }
}
