<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelSessionRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false]);
    }

    public function test_expired_store_session_redirects_to_login_and_json_gets_unauthorized(): void
    {
        $s = Store::create(['owner_id' => User::factory()->create()->id, 'name' => 'Test', 'slug' => 'session-test', 'template' => 'aurora', 'status' => 'active']);
        $this->get('/stores/'.$s->id.'/vouchers')->assertRedirect('/login');
        $this->getJson('/stores/'.$s->id.'/vouchers')->assertUnauthorized();
        $this->get('/admin/vouchers')->assertRedirect('/admin/login');
        $this->get('/staff/'.$s->id.'/dashboard')->assertRedirect('/staff/'.$s->id.'/login');
        $this->get('/login')->assertOk();
        $this->get('/admin/login')->assertOk();
    }

    public function test_authenticated_wrong_owner_remains_forbidden(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $s = Store::create(['owner_id' => $owner->id, 'name' => 'Test', 'slug' => 'other-store', 'template' => 'aurora', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['role' => 'owner']))->get('/stores/'.$s->id.'/vouchers')->assertForbidden();
    }

    public function test_staff_login_hint_and_public_pages_do_not_require_owner_login(): void
    {
        $store = Store::create(['owner_id' => User::factory()->create()->id, 'name' => 'Test', 'slug' => 'staff-hint', 'template' => 'aurora', 'status' => 'active']);
        $this->withCookie('panel_login_role', 'staff')->get('/stores/'.$store->id.'/vouchers')->assertRedirect('/staff/'.$store->id.'/login');
        $this->get('/staff/'.$store->id.'/login')->assertOk();
        $this->get('/paket')->assertOk();
        $this->getJson('/api/commerce/digiflazz')->assertStatus(405);
    }
}
