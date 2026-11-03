<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoStoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DemoStoreSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_preserves_edits_and_does_not_contact_hanshop(): void
    {
        config(['platform.admin' => ['name' => 'Admin Demo', 'email' => 'demo-admin@example.test', 'password' => DemoStoreSeeder::PASSWORD]]);
        Http::preventStrayRequests();
        $this->seed(DatabaseSeeder::class);
        $store = Store::where('slug', 'demo-aurora')->firstOrFail();
        $store->update(['name' => 'Nama yang diedit', 'expires_at' => now()->addDays(90)]);
        $expiry = $store->expires_at;
        $staff = StoreStaff::where('store_id', $store->id)->where('username', 'manager')->firstOrFail();
        $staff->update(['active' => false, 'password' => 'PasswordYangDiubah123!']);
        $hash = $staff->password;
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('stores', 8);
        $this->assertSame(0, Store::where('is_trial', true)->count());
        $this->assertDatabaseHas('stores', ['slug' => 'demo-bot', 'is_trial' => false]);
        $this->assertDatabaseCount('store_staff', 21);
        $this->assertSame('Nama yang diedit', $store->fresh()->name);
        $this->assertTrue($expiry->equalTo($store->fresh()->expires_at));
        $this->assertSame($hash, $staff->fresh()->password);
        $this->assertFalse($staff->fresh()->active);
        $this->assertSame(0, User::whereNotNull('hanshop_user_id')->count());
        $this->assertTrue(Store::where('slug', 'demo-grace')->first()->acceptsOrders());
        $this->assertFalse(Store::where('slug', 'demo-expired')->first()->acceptsOrders());
        $this->assertFalse(Store::where('slug', 'demo-draft')->first()->acceptsOrders());
        Http::assertNothingSent();
    }

    public function test_credentials_support_admin_and_scoped_staff_login(): void
    {
        $this->withoutVite();
        config(['platform.domain' => 'localhost']);
        $this->seed(DemoStoreSeeder::class);
        $this->post('/admin/login', ['email' => 'demo-admin@example.test', 'password' => DemoStoreSeeder::PASSWORD])->assertRedirect('/admin');
        $this->get('/admin')->assertOk();
        $this->post('/logout');
        $store = Store::where('slug', 'demo-aurora')->firstOrFail();
        $other = Store::where('slug', 'demo-nebula')->firstOrFail();
        $this->post('/staff/'.$store->id.'/login', ['identifier' => 'viewer', 'password' => DemoStoreSeeder::PASSWORD])
            ->assertRedirect('/staff/'.$store->id.'/dashboard');
        $this->get('/staff/'.$store->id.'/dashboard')->assertOk();
        $this->get('/staff/'.$other->id.'/dashboard')->assertForbidden();
        $this->post('/logout');
        $this->post('/staff/'.$store->id.'/login', ['identifier' => 'nonaktif', 'password' => DemoStoreSeeder::PASSWORD])
            ->assertSessionHasErrors('identifier');
        $this->assertGuest('staff');
    }

    public function test_seed_refuses_production_before_writing_data(): void
    {
        $this->app->instance('env', 'production');
        try {
            app(DemoStoreSeeder::class)->run();
            $this->fail('Production must reject demo seed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Demo hanya untuk local/development/testing.', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('stores', 0);
    }
}
