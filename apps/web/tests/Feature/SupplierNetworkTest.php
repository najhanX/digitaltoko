<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use App\Support\SupplierNetwork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SupplierNetworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_configured_public_ips_are_exposed_without_network_detection(): void
    {
        Http::preventStrayRequests();
        config(['suppliers.digiflazz_outbound_ip' => null]);
        foreach ([null, '', '127.0.0.1', '192.168.1.1', 'supplier.example', 'not-an-ip'] as $value) {
            config(['suppliers.outbound_ip' => $value]);
            $this->assertNull(SupplierNetwork::outboundIp('digiflazz'));
            $this->assertNull(SupplierNetwork::outboundIp('otomax'));
        }
        config(['suppliers.outbound_ip' => ' 8.8.4.4 ']);
        $this->assertSame('8.8.4.4', SupplierNetwork::outboundIp('digiflazz'));
        $this->assertSame('8.8.4.4', SupplierNetwork::outboundIp('otomax'));
        config(['suppliers.digiflazz_outbound_ip' => '1.1.1.1']);
        $this->assertSame('1.1.1.1', SupplierNetwork::outboundIp('digiflazz'));
        $this->assertSame('8.8.4.4', SupplierNetwork::outboundIp('otomax'));
        Http::assertNothingSent();
    }

    public function test_owner_and_authorized_staff_receive_whitelist_ip_on_both_supplier_pages(): void
    {
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false, 'suppliers.outbound_ip' => '8.8.4.4', 'suppliers.digiflazz_outbound_ip' => null]);
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved']);
        $store = Store::create(['owner_id' => $owner->id, 'name' => 'Supplier demo', 'slug' => 'supplier-demo', 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'expires_at' => now()->addDays(30)]);
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Supplier staff', 'username' => 'supplier-staff', 'email' => 'supplier@example.test', 'password' => 'test-only', 'active' => true, 'permissions' => ['products.view', 'suppliers.manage']]);
        foreach (['web' => $owner, 'staff' => $staff] as $guard => $actor) {
            auth('web')->logout();
            $this->actingAs($actor, $guard);
            foreach (['sources', 'otomax'] as $menu) {
                $this->get('http://localhost/stores/'.$store->id.'/'.$menu)->assertOk()->assertInertia(fn (Assert $page) => $page->where('supplierOutboundIp', '8.8.4.4'));
            }
        }
        config(['suppliers.outbound_ip' => null]);
        $this->get('http://localhost/stores/'.$store->id.'/sources')->assertOk()->assertInertia(fn (Assert $page) => $page->where('supplierOutboundIp', null));
        Http::assertNothingSent();
    }
}
