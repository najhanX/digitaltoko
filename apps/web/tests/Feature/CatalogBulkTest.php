<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\CustomerOrder;
use App\Models\PremiumStock;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use App\Services\StoreCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogBulkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
    }

    private function shop(): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved'])->id,
            'name' => 'Toko Bulk', 'slug' => 'bulk-'.Str::lower(Str::random(8)), 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'expires_at' => now()->addMonth(), 'template' => 'nebula']);
    }

    private function product(Store $store): CatalogProduct
    {
        $product = CatalogProduct::factory()->create(['store_id' => $store->id]);
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'stock', 'source_key' => 'stock', 'active' => true]);
        PremiumStock::factory()->create(['store_id' => $store->id, 'product_id' => $product->id]);

        return $product;
    }

    private function order(Store $store, CatalogProduct $product, string $status): CustomerOrder
    {
        return CustomerOrder::create(['store_id' => $store->id, 'product_id' => $product->id, 'number' => 'DT'.Str::ulid(), 'idempotency_key' => Str::random(20), 'request_hash' => str_repeat('a', 64),
            'channel' => 'web', 'purpose' => 'product', 'status' => $status, 'payment_status' => $status === 'success' ? 'paid' : 'unpaid', 'gateway' => 'midtrans', 'phone' => '628000000001', 'destination' => '628000000001',
            'snapshot' => ['name' => $product->name], 'amount' => 20000, 'total' => 20000, 'expires_at' => now()->addMinutes(15)]);
    }

    public function test_owner_can_toggle_one_or_many_without_changing_prices_stock_or_parsing(): void
    {
        $store = $this->shop();
        $a = $this->product($store);
        $b = $this->product($store);
        $url = '/stores/'.$store->id.'/products/bulk';
        $this->actingAs($store->owner)->post($url, ['action' => 'deactivate', 'ids' => [$a->id, $b->id]])->assertSessionHasNoErrors();
        $this->assertFalse($a->fresh()->active);
        $this->assertFalse($b->fresh()->active);
        $catalog = app(StoreCatalog::class);
        $this->assertFalse($catalog->present($catalog->query($store, false)->find($a->id), $store)['available']);
        $this->assertSame(20000, $a->fresh()->sale_price);
        $this->assertDatabaseCount('premium_stocks', 2);
        $this->assertDatabaseCount('product_mappings', 2);
        $this->post($url, ['action' => 'activate', 'ids' => [$a->id]])->assertSessionHasNoErrors();
        $this->assertTrue($a->fresh()->active);
        $this->assertFalse($b->fresh()->active);
        $this->assertTrue($catalog->present($catalog->query($store, false)->find($a->id), $store)['available']);
    }

    public function test_bulk_actions_are_store_scoped_permission_checked_and_validate_selection(): void
    {
        $store = $this->shop();
        $a = $this->product($store);
        $foreign = $this->product($this->shop());
        $url = '/stores/'.$store->id.'/products/bulk';
        $this->actingAs($store->owner)->post($url, ['action' => 'deactivate', 'ids' => [$a->id, $foreign->id]])->assertSessionHasErrors('ids');
        $this->assertTrue($a->fresh()->active);
        $this->assertTrue($foreign->fresh()->active);
        $this->post($url, ['action' => 'delete', 'ids' => [$a->id, $a->id]])->assertSessionHasErrors('ids.0');
        $this->post($url, ['action' => 'delete', 'ids' => []])->assertSessionHasErrors('ids');
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Staff', 'username' => 'bulkreader', 'email' => 'bulkreader@test.invalid', 'password' => 'test-only', 'active' => true, 'permissions' => ['products.view']]);
        auth('web')->logout();
        $this->actingAs($staff, 'staff')->post($url, ['action' => 'deactivate', 'ids' => [$a->id]])->assertForbidden();
        $staff->update(['permissions' => ['products.manage']]);
        $this->post($url, ['action' => 'deactivate', 'ids' => [$a->id]])->assertSessionHasNoErrors();
        $store->update(['status' => 'suspended']);
        $this->post($url, ['action' => 'activate', 'ids' => [$a->id]])->assertForbidden();
    }

    public function test_bulk_delete_cleans_ready_stock_parsing_and_design_but_preserves_historical_orders(): void
    {
        $store = $this->shop();
        $a = $this->product($store);
        $b = $this->product($store);
        $keep = $this->product($store);
        $history = $this->order($store, $a, 'success');
        $store->update(['branding' => ['design_revision' => 4, 'design' => ['flash_product_ids' => [$a->id, $keep->id], 'trending_product_ids' => [$b->id], 'flash_discounts' => [$a->id => ['type' => 'fixed', 'value' => '1000']]]]]);
        $this->actingAs($store->owner)->post('/stores/'.$store->id.'/products/bulk', ['action' => 'delete', 'ids' => [$a->id, $b->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('catalog_products', ['id' => $a->id]);
        $this->assertDatabaseMissing('catalog_products', ['id' => $b->id]);
        $this->assertDatabaseCount('premium_stocks', 1);
        $this->assertDatabaseCount('product_mappings', 1);
        $this->assertNull($history->fresh()->product_id);
        $this->assertSame($a->name, $history->fresh()->snapshot['name']);
        $this->assertSame([$keep->id], $store->fresh()->branding['design']['flash_product_ids']);
        $this->assertSame([], $store->fresh()->branding['design']['flash_discounts']);
        $this->assertSame(5, $store->fresh()->branding['design_revision']);
    }

    public function test_delete_is_atomic_and_rejects_sent_stock_or_pending_orders_but_deactivation_still_works(): void
    {
        $store = $this->shop();
        $a = $this->product($store);
        $b = $this->product($store);
        $url = '/stores/'.$store->id.'/products';
        $stock = PremiumStock::where('product_id', $b->id)->firstOrFail();
        $stock->update(['status' => 'sent']);
        $this->actingAs($store->owner)->post($url.'/bulk', ['action' => 'delete', 'ids' => [$a->id, $b->id]])->assertSessionHasErrors('products');
        $this->assertDatabaseCount('catalog_products', 2);
        $stock->update(['status' => 'ready']);
        $this->order($store, $b, 'pending');
        $this->post($url.'/bulk', ['action' => 'delete', 'ids' => [$a->id, $b->id]])->assertSessionHasErrors('products');
        $this->delete($url.'/'.$b->id)->assertSessionHasErrors('products');
        $this->assertDatabaseCount('catalog_products', 2);
        $this->post($url.'/bulk', ['action' => 'deactivate', 'ids' => [$a->id, $b->id]])->assertSessionHasNoErrors();
        $this->assertFalse($b->fresh()->active);
    }
}
