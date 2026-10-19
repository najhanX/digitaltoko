<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\PremiumStock;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use App\Services\CustomerCommerce;
use App\Services\GameIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GameIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false, 'cache.default' => 'array', 'services.game_identity.url' => 'https://nickname.example.test/nickname']);
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function shop(): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved'])->id,
            'name' => 'Game Shop', 'slug' => 'game-'.Str::lower(Str::random(8)), 'category' => 'full', 'plan' => 'galaxy',
            'template' => 'nebula', 'status' => 'active', 'expires_at' => now()->addMonth()]);
    }

    private function product(Store $store, string $category = 'Mobile Legends'): CatalogProduct
    {
        $product = CatalogProduct::factory()->create(['store_id' => $store->id, 'service' => 'Game', 'category' => $category, 'kind' => 'premium', 'sale_price' => 15000, 'active' => true]);
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'stock', 'source_key' => 'stock', 'active' => true]);
        PremiumStock::factory()->create(['store_id' => $store->id, 'product_id' => $product->id, 'status' => 'ready', 'cost' => 10000]);

        return $product;
    }

    private function lookup(Store $store, CatalogProduct $product, array $extra = []): array
    {
        return $this->postJson('http://'.$store->slug.'.localhost/game-identity', [...['category_id' => $product->category_id, 'id' => '1114917746', 'server' => '13486'], ...$extra])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    }

    private function upstream(): void
    {
        Http::fake(['nickname.example.test/*' => Http::response(['success' => true, 'id' => '1114917746', 'server' => '13486', 'name' => 'Player%20One'])]);
    }

    public function test_auto_detection_and_explicit_category_settings_supply_the_right_form_fields(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $url = 'http://'.$store->slug.'.localhost/kategori/'.$product->category_id;
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('gameProfile.game', 'ml')->where('gameProfile.server_label', 'Zone ID')->where('gameProfile.destination_format', 'concat'));
        $this->get('http://'.$store->slug.'.localhost/checkout?product='.$product->id)->assertInertia(fn (Assert $page) => $page->where('gameProfile.category_id', $product->category_id));
        $store->update(['branding' => ['category_games' => [$product->category_id => ['game' => 'none', 'destination_format' => 'auto']]]]);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('gameProfile', null));
        $store->update(['branding' => ['category_games' => [$product->category_id => ['game' => 'pgr', 'destination_format' => 'pipe']]]]);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('gameProfile.game', 'pgr')->where('gameProfile.server_options', ['AP', 'EU', 'NA'])->where('gameProfile.destination_format', 'pipe'));
        Http::assertNothingSent();
    }

    public function test_public_lookup_uses_string_fields_and_shares_cache_but_not_proofs_between_stores(): void
    {
        $this->upstream();
        $one = $this->shop();
        $first = $this->product($one);
        $data = $this->lookup($one, $first);
        $this->assertSame('Player One', $data['nickname']);
        $this->assertSame('111491774613486', $data['destination']);
        $this->lookup($one, $first);
        $two = $this->shop();
        $second = $this->product($two);
        $other = $this->lookup($two, $second);
        $this->assertNotSame($data['verification'], $other['verification']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://nickname.example.test/nickname/ml?decode=false' && $request['id'] === '1114917746' && $request['server'] === '13486');
    }

    public function test_invalid_fields_foreign_categories_and_inactive_stores_do_not_call_the_upstream(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $foreign = $this->product($this->shop());
        $url = 'http://'.$store->slug.'.localhost/game-identity';
        $this->postJson($url, ['category_id' => $product->category_id, 'id' => '1114917746'])->assertUnprocessable()->assertJsonValidationErrors('server');
        $this->postJson($url, ['category_id' => $product->category_id, 'id' => 'https://example.test', 'server' => '13486'])->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->postJson($url, ['category_id' => $foreign->category_id, 'id' => '1114917746', 'server' => '13486'])->assertNotFound();
        $store->update(['status' => 'expired', 'expires_at' => now()->subDays(8)]);
        $this->postJson($url, ['category_id' => $product->category_id, 'id' => '1114917746', 'server' => '13486'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_games_without_zones_and_riot_tags_keep_their_original_ids(): void
    {
        $store = $this->shop();
        Http::fake(['nickname.example.test/*' => fn ($request) => Http::response(['success' => true, 'id' => $request['id'], 'name' => 'Player One'])]);
        $freeFire = $this->product($store, 'Free Fire');
        $account = $this->lookup($store, $freeFire, ['id' => '225009777', 'server' => null]);
        $this->assertSame('225009777', $account['destination']);
        $this->assertSame('', $account['server']);
        $valorant = $this->product($store, 'Valorant');
        $riot = $this->lookup($store, $valorant, ['id' => 'Player Name#123', 'server' => null]);
        $this->assertSame('Player Name#123', $riot['destination']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/valo?') && $request['id'] === 'Player Name#123');
    }

    public function test_invalid_id_and_upstream_outages_are_distinguished_without_a_successful_proof(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $url = 'http://'.$store->slug.'.localhost/game-identity';
        $failures = [[['success' => true, 'name' => 'Wrong user', 'id' => '99999999'], 200], [['success' => true, 'name' => 'Wrong zone', 'id' => '1114917746', 'server' => '99999'], 200], [['success' => true], 200], ['<html>unavailable</html>', 200], [[], 500], [[], 429]];
        $sequence = Http::sequence()->push(['success' => false, 'message' => 'Not found']);
        foreach ($failures as [$body, $status]) {
            $sequence->push($body, $status);
        }
        Http::fake(['nickname.example.test/*' => $sequence]);
        $input = ['category_id' => $product->category_id, 'id' => '1114917746', 'server' => '13486'];
        $this->postJson($url, $input)->assertUnprocessable()->assertJsonValidationErrors('id')->assertJsonMissingPath('data.verification');
        $this->postJson($url, $input)->assertUnprocessable();
        Http::assertSentCount(1);
        foreach (array_keys($failures) as $case) {
            Cache::flush();
            $this->postJson($url, $input)->assertStatus(503)->assertJsonMissingPath('data.verification');
        }
    }

    public function test_checkout_requires_matching_unexpired_proof_before_debiting_wallet_and_keeps_the_nickname(): void
    {
        $this->upstream();
        $store = $this->shop();
        $product = $this->product($store);
        $other = $this->product($store, 'Magic Chess: Go Go');
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Player', 'phone' => '6281234567890', 'phone_verified_at' => now(), 'balance' => 100000]);
        $data = $this->lookup($store, $product);
        $input = ['purpose' => 'product', 'product_id' => $product->id, 'phone' => $customer->phone, 'destination' => $data['destination'], 'payment' => 'wallet', 'idempotency_key' => (string) Str::uuid()];
        $commerce = app(CustomerCommerce::class);
        foreach ([[], ['game_verification' => 'forged'], ['game_verification' => $data['verification'], 'destination' => '9999999913486'], ['game_verification' => $data['verification'], 'product_id' => $other->id]] as $change) {
            try {
                $commerce->begin($store, [...$input, ...$change], $customer);
                $this->fail('An invalid proof must never create a paid order.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('game_verification', $error->errors());
            }
        }
        $this->assertSame(100000, $customer->fresh()->balance);
        $this->assertDatabaseCount('customer_orders', 0);
        $order = $commerce->begin($store, [...$input, 'game_verification' => $data['verification']], $customer);
        $this->assertSame('111491774613486', $order->destination);
        $this->assertSame('Player One', $order->snapshot['game_account']['nickname']);
        $this->assertSame(85000, $customer->fresh()->balance);
        $this->assertSame($order->id, $commerce->begin($store, [...$input, 'game_verification' => $data['verification']], $customer)->id);
        $this->assertSame(85000, $customer->fresh()->balance);
        $this->travel(11)->minutes();
        try {
            $commerce->begin($store, [...$input, 'idempotency_key' => (string) Str::uuid(), 'game_verification' => $data['verification']], $customer);
            $this->fail('An expired proof must be rejected.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('game_verification', $error->errors());
        }
        $this->assertDatabaseCount('customer_orders', 1);
    }

    public function test_one_category_verification_can_be_used_for_different_nominals_without_another_lookup(): void
    {
        $this->upstream();
        $store = $this->shop();
        $first = $this->product($store);
        $second = $this->product($store);
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Player', 'phone' => '6281234567890', 'phone_verified_at' => now(), 'balance' => 100000]);
        $account = $this->lookup($store, $first);
        foreach ([$first, $second] as $product) {
            $order = app(CustomerCommerce::class)->begin($store, ['product_id' => $product->id, 'destination' => $account['destination'], 'game_verification' => $account['verification'], 'payment' => 'wallet', 'idempotency_key' => (string) Str::uuid()], $customer);
            $this->assertSame('Player One', $order->snapshot['game_account']['nickname']);
            $this->assertSame('111491774613486', $order->destination);
        }
        Http::assertSentCount(1);
        $this->assertSame(70000, $customer->fresh()->balance);
    }

    public function test_category_configuration_respects_staff_permissions_and_destination_formats(): void
    {
        $store = $this->shop();
        $product = $this->product($store, 'Game khusus');
        $url = 'http://localhost/stores/'.$store->id.'/categories/'.$product->category_id;
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Staff', 'username' => 'game-staff', 'email' => 'staff@example.test', 'password' => 'fixture-only', 'active' => true, 'permissions' => ['products.view']]);
        $this->actingAs($staff, 'staff')->patch($url, ['name' => 'Game khusus', 'game_validation' => 'ml', 'destination_format' => 'parentheses'])->assertForbidden();
        $staff->update(['permissions' => ['products.manage']]);
        $this->patch($url, ['name' => 'Game khusus', 'game_validation' => 'ml', 'destination_format' => 'parentheses'])->assertSessionHasNoErrors();
        $identity = app(GameIdentity::class);
        $profile = $identity->profile($store->fresh(), $product->taxonomyCategory);
        $this->assertSame('123456(13486)', $identity->destination($profile, '123456', '13486'));
        $this->patch($url, ['name' => 'Game khusus', 'game_validation' => '../../evil'])->assertSessionHasErrors('game_validation');
        $this->patch($url, ['name' => 'Game khusus', 'game_validation' => 'ml', 'destination_format' => 'arbitrary'])->assertSessionHasErrors('destination_format');
    }
}
