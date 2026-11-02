<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\BotManager;
use App\Services\Provisioning;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'cache.default' => 'array', 'inertia.ssr.enabled' => false, 'services.hanshop.url' => 'https://hanshop.example', 'services.hanshop.token' => str_repeat('a', 40)]);
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function paid(string $category = 'bot', bool $free = false): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD'.(User::count() + 1)]);
        $store = Store::create(['owner_id' => $owner->id, 'name' => 'Provisioning Test', 'slug' => 'case-'.User::count(), 'category' => $category, 'plan' => 'galaxy', 'status' => 'provisioning', 'template' => 'aurora']);
        $invoice = SubscriptionInvoice::create(['store_id' => $store->id, 'owner_id' => $owner->id, 'status' => 'paid', 'purpose' => 'initial', 'snapshot' => ['category' => $category, 'plan' => 'galaxy', 'price' => $free ? 0 : 50000, 'duration_days' => 30, 'name' => 'Test', 'voucher' => $free ? ['discount' => 50000] : null], 'total' => $free ? 0 : 50123, 'paid_at' => now(), 'provider_id' => $free ? null : 'DTTEST']);

        if ($category === 'web') {
            Cache::forever('bot:'.$store->id.':state', ['status' => 'CONNECTED']);
        }

        return [$store, $invoice, app(Provisioning::class)->monitor($store)];
    }

    public function test_missing_whatsapp_waits_without_starting_duration(): void
    {
        [$store,$invoice,$case] = $this->paid();
        app(SubscriptionService::class)->tick($store);
        $this->assertSame('waiting_owner', $case->fresh()->state);
        $this->assertNull($store->fresh()->starts_at);
        $this->assertSame('paid', $invoice->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_report_holds_auto_activation_and_admin_retry_preserves_owner_confirmation(): void
    {
        [$store,$invoice,$case] = $this->paid('web');
        $service = app(Provisioning::class);
        $service->action($store, $store->owner, 'report', 'Halaman toko bermasalah ketika disiapkan.');
        $store->update(['ready_at' => now()->subDays(2)]);
        app(SubscriptionService::class)->tick($store);
        $this->assertNull($store->fresh()->starts_at);
        $service->action($store, User::factory()->create(['role' => 'admin']), 'retry', 'Layanan platform sudah diperiksa dan dipulihkan.');
        $this->assertSame('ready', $case->fresh()->state);
        app(SubscriptionService::class)->activate($store);
        $this->assertSame('active', $store->fresh()->status);
        $this->assertSame('resolved', $case->fresh()->state);
    }

    public function test_bot_platform_failure_is_recorded_and_retry_does_not_buy_again(): void
    {
        [$store,$invoice,$case] = $this->paid();
        config(['services.bot_manager.token' => '']);
        try {
            app(BotManager::class)->command($store, 'start');
        } catch (ValidationException) {
        }$this->assertSame('failed', $case->fresh()->state);
        app(Provisioning::class)->action($store, $store->owner, 'retry', 'Periksa ulang layanan bot yang telah dipulihkan.');
        $this->assertSame('waiting_owner', $case->fresh()->state);
        $this->assertDatabaseCount('subscription_invoices', 1);
        Http::assertNothingSent();
    }

    public function test_refund_includes_unique_code_and_replay_does_not_credit_twice(): void
    {
        [$store,$invoice,$case] = $this->paid();
        $service = app(Provisioning::class);
        $service->action($store, $store->owner, 'request_refund', 'Gangguan platform belum dapat diperbaiki.');
        $admin = User::factory()->create(['role' => 'admin']);
        Http::fake(['*/billing/refund' => Http::response(['status' => 'SUCCESS', 'data' => ['status' => 'REFUNDED', 'invoice_id' => $invoice->id, 'id_user' => $store->owner->hanshop_user_id, 'amount' => 50123]])]);
        $service->action($store, $admin, 'approve_refund', 'Gangguan platform sudah dikonfirmasi admin.');
        $service->action($store->fresh(), $admin, 'approve_refund', 'Memeriksa persetujuan yang sama sekali lagi.');
        $this->assertSame('refunded', $invoice->fresh()->status);
        $this->assertSame('draft', $store->fresh()->status);
        $this->assertSame(50123, $case->fresh()->refund_amount);
        $this->assertNull($store->fresh()->starts_at);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('customer_ledgers', 0);
    }

    public function test_timeout_holds_activation_until_refund_is_confirmed(): void
    {
        [$store,$invoice,$case] = $this->paid('web');
        $service = app(Provisioning::class);
        $service->action($store, $store->owner, 'request_refund', 'Gangguan platform memerlukan penyelesaian.');
        $admin = User::factory()->create(['role' => 'admin']);
        Http::fake(['*/billing/refund' => Http::sequence()->push([], 503)->push(['status' => 'SUCCESS', 'data' => ['status' => 'REFUNDED', 'invoice_id' => $invoice->id, 'id_user' => $store->owner->hanshop_user_id, 'amount' => 50123]])]);
        $service->action($store, $admin, 'approve_refund', 'Gangguan platform telah diperiksa oleh admin.');
        $this->assertSame('refund_processing', $case->fresh()->state);
        try {
            app(SubscriptionService::class)->activate($store);
            $this->fail('Expected hold');
        } catch (ValidationException) {
        }$service->refund($case->fresh());
        $this->assertSame('refunded', $case->fresh()->state);
        Http::assertSentCount(2);
    }

    public function test_free_voucher_cancellation_has_no_wallet_credit(): void
    {
        [$store,$invoice,$case] = $this->paid('web', true);
        $service = app(Provisioning::class);
        $service->action($store, $store->owner, 'request_refund', 'Platform gagal disiapkan memakai voucher gratis.');
        $service->action($store, User::factory()->create(['role' => 'admin']), 'approve_refund', 'Gangguan platform dikonfirmasi tanpa uang dibayar.');
        $this->assertSame('refunded', $invoice->fresh()->status);
        $this->assertSame(0, $case->fresh()->refund_amount);
        Http::assertNothingSent();
    }

    public function test_approval_requires_admin_fault_confirmation_and_correct_owner_scope(): void
    {
        [$store,$invoice,$case] = $this->paid();
        $url = '/stores/'.$store->id.'/provisioning';
        $data = ['case_id' => $case->id, 'action' => 'approve_refund', 'reason' => 'Pemeriksaan kegagalan dilakukan administrator.'];
        $this->actingAs($store->owner)->post($url, $data)->assertSessionHasErrors('action');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post($url, $data)->assertSessionHasErrors(['platform_fault', 'confirmation']);
        $this->actingAs(User::factory()->create(['role' => 'owner']))->post($url, $data)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_active_rental_and_stale_case_cannot_be_refunded(): void
    {
        [$store,$invoice,$case] = $this->paid('web');
        $service = app(Provisioning::class);
        $admin = User::factory()->create(['role' => 'admin']);
        try {
            $service->action($store, $admin, 'report', 'Kendala setelah data telah berubah.', '00000000-0000-0000-0000-000000000000');
            $this->fail('Expected stale case');
        } catch (ValidationException) {
        }app(SubscriptionService::class)->activate($store);
        try {
            $service->action($store->fresh(), $admin, 'approve_refund', 'Paket aktif tidak boleh mendapat refund.');
            $this->fail('Expected active guard');
        } catch (ValidationException) {
        }$this->assertSame('applied', $invoice->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_owner_request_and_admin_free_refund_work_through_panel_routes(): void
    {
        [$store, $invoice, $case] = $this->paid('web', true);
        $url = '/stores/'.$store->id.'/provisioning';
        $this->actingAs($store->owner)->post($url, ['case_id' => $case->id, 'action' => 'request_refund', 'reason' => 'Gangguan platform memerlukan pemeriksaan admin.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post($url, ['case_id' => $case->id, 'action' => 'approve_refund', 'reason' => 'Platform gagal disiapkan sesuai pemeriksaan admin.', 'platform_fault' => true, 'confirmation' => $store->slug])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('refunded', $invoice->fresh()->status);
        $this->assertSame('draft', $store->fresh()->status);
        Http::assertNothingSent();
    }
}
