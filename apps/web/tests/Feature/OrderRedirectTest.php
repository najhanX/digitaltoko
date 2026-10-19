<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\CustomerOrder;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerCommerce;
use App\Services\CustomerPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false, 'cache.default' => 'array', 'suppliers.cache_store' => 'array']);
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function invoices(): array
    {
        $store = Store::create(['owner_id' => User::factory()->create(['role' => 'owner'])->id, 'name' => 'Redirect test', 'slug' => Str::lower(Str::random(10)), 'category' => 'full', 'plan' => 'galaxy', 'template' => 'aurora', 'status' => 'active', 'expires_at' => now()->addMonth(), 'sandbox' => true]);
        $product = CatalogProduct::create(['store_id' => $store->id, 'code' => 'TEST', 'name' => 'Test product', 'kind' => 'premium', 'service' => 'Aplikasi', 'category' => 'Canva', 'sale_price' => 15000, 'active' => true]);
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'stock', 'source_key' => 'stock', 'active' => true]);
        $engine = app(CustomerCommerce::class);
        $engine->settings($store)->update(['allow_guest' => true]);
        $input = ['product_id' => $product->id, 'phone' => '6281234567890', 'payment' => 'gateway', 'guest_checkout_hash' => hash('sha256', 'browser')];
        $old = $engine->begin($store, [...$input, 'idempotency_key' => (string) Str::uuid()], null);
        $old->update(['status' => 'failed', 'payment_status' => 'paid', 'completed_at' => now()]);
        $new = $engine->begin($store, [...$input, 'idempotency_key' => (string) Str::uuid()], null);

        return [$old, $new];
    }

    private function invoiceUrl(CustomerOrder $order): string
    {
        return 'http://'.$order->store->slug.'.localhost/orders/'.$order->id.'?access='.app(CustomerCommerce::class)->token($order);
    }

    private function actionUrl(CustomerOrder $order, string $action): string
    {
        return 'http://'.$order->store->slug.'.localhost/orders/'.$order->id.'/'.$action.'?access='.app(CustomerCommerce::class)->token($order);
    }

    public function test_simulation_stays_on_new_invoice_even_when_session_remembers_old_failed_order(): void
    {
        [$old, $new] = $this->invoices();
        $this->withSession(['guest_checkout_key' => 'browser', '_previous' => ['url' => $this->invoiceUrl($old)]])->post($this->actionUrl($new, 'simulate'))->assertRedirect($this->invoiceUrl($new));
        $this->assertSame('success', $new->fresh()->status);
        $this->assertSame('failed', $old->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_refresh_success_and_failure_stay_on_target_invoice_without_referrer(): void
    {
        foreach ([false, true] as $fail) {
            [$old, $new] = $this->invoices();
            $this->mock(CustomerPayments::class, function ($mock) use ($new, $fail) {
                $expectation = $mock->shouldReceive('start')->once()->with(\Mockery::on(fn ($order) => $order->id === $new->id));
                if ($fail) {
                    $expectation->andThrow(new \RuntimeException('Test unavailable'));
                } else {
                    $mock->shouldReceive('refresh')->once();
                }
            });
            $response = $this->withSession(['_previous' => ['url' => $this->invoiceUrl($old)]])->post($this->actionUrl($new, 'refresh'));
            $response->assertRedirect($this->invoiceUrl($new));
            if ($fail) {
                $response->assertSessionHasErrors('payment');
            }
        }
    }

    public function test_cancellation_success_and_failure_stay_on_target_invoice(): void
    {
        foreach ([false, true] as $fail) {
            [$old, $new] = $this->invoices();
            if ($fail) {
                $this->mock(CustomerPayments::class, fn ($mock) => $mock->shouldReceive('cancel')->once()->andThrow(new \RuntimeException('Test unavailable')));
            }
            $response = $this->withSession(['guest_checkout_key' => 'browser', '_previous' => ['url' => $this->invoiceUrl($old)]])->post($this->actionUrl($new, 'cancel'));
            $response->assertRedirect($this->invoiceUrl($new));
            if ($fail) {
                $response->assertSessionHasErrors('payment');
            } else {
                $this->assertSame('expired', $new->fresh()->status);
            }
        }
    }

    public function test_expired_simulation_and_paid_cancellation_validation_errors_stay_on_current_invoice(): void
    {
        foreach (['simulate', 'cancel'] as $action) {
            [$old, $new] = $this->invoices();
            if ($action === 'simulate') {
                $new->update(['expires_at' => now()->subMinute()]);
            } else {
                app(CustomerPayments::class)->simulate($new);
            }
            $this->withSession(['guest_checkout_key' => 'browser', '_previous' => ['url' => $this->invoiceUrl($old)]])
                ->post($this->actionUrl($new, $action))->assertRedirect($this->invoiceUrl($new))->assertSessionHasErrors('commerce');
        }
        Http::assertNothingSent();
    }
}
