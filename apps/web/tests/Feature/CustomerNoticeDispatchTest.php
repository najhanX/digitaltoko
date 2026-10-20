<?php

namespace Tests\Feature;

use App\Jobs\SendCustomerNotice;
use App\Models\CatalogProduct;
use App\Models\CustomerNotice;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\User;
use App\Services\BotManager;
use App\Services\CustomerCommerce;
use App\Services\CustomerDelivery;
use App\Support\HanshopIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerNoticeDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.bot_manager.url' => 'http://127.0.0.1:3099', 'services.bot_manager.token' => str_repeat('t', 40), 'mail.default' => 'array', 'cache.default' => 'array']);
    }

    private function shop(string $category = 'full'): Store
    {
        $store = Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD-TEST'])->id, 'name' => 'Notice test', 'slug' => Str::lower(Str::random(10)), 'category' => $category, 'plan' => 'galaxy', 'status' => 'active', 'expires_at' => now()->addMonth(), 'sandbox' => true]);
        Cache::put('bot:'.$store->id.':state', ['status' => 'CONNECTED'], 90);

        return $store;
    }

    private function notice(Store $store): CustomerNotice
    {
        return CustomerNotice::create(['store_id' => $store->id, 'event' => 'test:'.Str::uuid(), 'phone' => '6281234567890', 'message' => 'Dummy notice']);
    }

    public function test_notice_is_queued_after_commit_and_not_after_rollback(): void
    {
        $store = $this->shop();
        DB::beginTransaction();
        $notice = $this->notice($store);
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(SendCustomerNotice::class, fn ($job) => $job->noticeId === $notice->id);
        Queue::fake();
        DB::beginTransaction();
        $this->notice($store);
        DB::rollBack();
        Queue::assertNothingPushed();
    }

    public function test_connected_store_signals_its_worker_without_marking_unsent_message_delivered(): void
    {
        $store = $this->shop();
        $notice = $this->notice($store);
        Http::fake(['127.0.0.1:3099/*' => Http::response(['status' => 'SUCCESS'])]);
        (new SendCustomerNotice($notice->id))->handle(app(CustomerDelivery::class), app(BotManager::class));
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:3099/stores/'.$store->id.'/notifications');
        Http::assertSentCount(1);
        $this->assertNull($notice->fresh()->wa_sent_at);
    }

    public function test_web_store_signals_its_own_worker_without_using_platform_fallback(): void
    {
        $store = $this->shop('web');
        $notice = $this->notice($store);
        $this->mock(HanshopIdentity::class, function ($mock) {
            $mock->shouldNotReceive('call');
        });
        Http::fake(['127.0.0.1:3099/*' => Http::response(['status' => 'SUCCESS'])]);
        (new SendCustomerNotice($notice->id))->handle(app(CustomerDelivery::class), app(BotManager::class));
        $this->assertNull($notice->fresh()->wa_sent_at);
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:3099/stores/'.$store->id.'/notifications');
    }

    public function test_manager_failure_keeps_outbox_for_retry_and_does_not_undo_business_transaction(): void
    {
        $notice = $this->notice($this->shop());
        Http::fake(['127.0.0.1:3099/*' => Http::response([], 503)]);
        try {
            (new SendCustomerNotice($notice->id))->handle(app(CustomerDelivery::class), app(BotManager::class));
            $this->fail('Failed delivery signal should be retried by queue.');
        } catch (ValidationException) {
            $this->assertNull($notice->fresh()->wa_sent_at);
        }
        $this->assertSame(3, (new SendCustomerNotice($notice->id))->tries);
    }

    public function test_web_order_initial_notice_is_idempotent_and_bot_does_not_duplicate_direct_receipt(): void
    {
        $store = $this->shop();
        $product = CatalogProduct::create(['store_id' => $store->id, 'code' => 'APP', 'name' => 'Test premium', 'kind' => 'premium', 'service' => 'Aplikasi', 'category' => 'Canva', 'sale_price' => 15000, 'active' => true]);
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'stock', 'source_key' => 'stock', 'active' => true]);
        $engine = app(CustomerCommerce::class);
        $engine->settings($store)->update(['allow_guest' => true]);
        $input = ['product_id' => $product->id, 'phone' => '6281234567890', 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()];
        $order = $engine->begin($store, $input, null);
        $engine->begin($store, $input, null);
        $engine->begin($store, [...$input, 'idempotency_key' => (string) Str::uuid()], null, 'bot');
        $this->assertSame(1, CustomerNotice::count());
        $notice = CustomerNotice::firstOrFail();
        $this->assertSame('order:'.$order->id.':awaiting_payment', $notice->event);
        $this->assertStringContainsString('menunggu pembayaran', $notice->message);
        $this->assertStringContainsString('15.000', $notice->message);
        Queue::assertPushed(SendCustomerNotice::class, 1);
    }
}
