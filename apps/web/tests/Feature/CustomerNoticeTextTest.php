<?php

namespace Tests\Feature;

use App\Models\CustomerOrder;
use App\Models\Store;
use App\Services\CustomerNoticeText;
use Tests\TestCase;

class CustomerNoticeTextTest extends TestCase
{
    private function order(array $changes = []): CustomerOrder
    {
        $order = new CustomerOrder([
            'number' => 'DTTEST123', 'purpose' => 'product', 'status' => 'pending', 'is_sandbox' => false,
            'snapshot' => ['name' => 'Pulsa 10.000', 'kind' => 'ppob'], 'destination' => '081234567890',
            'total' => 10123, 'credit_amount' => 10000, 'refund_amount' => 10123,
            'customer_id' => 'customer-test', 'message' => 'RAW-SUPPLIER-DIAGNOSTIC',
            'created_at' => '2026-10-27 03:20:00', 'paid_at' => '2026-10-27 03:22:00',
            'completed_at' => '2026-10-27 03:24:00', 'expires_at' => '2026-10-27 04:20:00', ...$changes,
        ]);
        $order->setRelation('store', new Store(['name' => 'Bogor Topup']));

        return $order;
    }

    public function test_order_notices_show_status_amount_and_real_wib_time_without_raw_supplier_diagnostics(): void
    {
        $formatter = app(CustomerNoticeText::class);
        foreach (['awaiting_payment', 'paid', 'pending', 'success', 'failed', 'review'] as $status) {
            $message = $formatter->order($this->order(), $status, 'https://shop.example/orders/test');
            $this->assertStringStartsWith('*BOGOR TOPUP*', $message);
            $this->assertStringContainsString('Pesanan: `DTTEST123`', $message);
            $this->assertStringContainsString('*Rp10.123*', $message);
            $this->assertStringContainsString('Tujuan: 081234567890', $message);
            $this->assertStringNotContainsString('RAW-SUPPLIER-DIAGNOSTIC', $message);
            $this->assertStringEndsWith('_Powered by DigitalToko_', $message);
        }
        $paid = $formatter->order($this->order(), 'paid', 'https://shop.example');
        $this->assertStringContainsString('Dibayar: 27/10/2026 10:22 WIB', $paid);
        $success = $formatter->order($this->order(), 'success', 'https://shop.example');
        $this->assertStringContainsString('Selesai: 27/10/2026 10:24 WIB', $success);
        $this->assertStringContainsString('Batas bayar: 27/10/2026 11:20 WIB', $formatter->order($this->order(), 'awaiting_payment', 'https://shop.example'));
    }

    public function test_premium_and_topup_notices_omit_destination_and_topup_distinguishes_credit_from_payment(): void
    {
        $formatter = app(CustomerNoticeText::class);
        $premium = $formatter->order($this->order(['snapshot' => ['name' => 'Canva Pro', 'kind' => 'premium']]), 'success', 'https://shop.example');
        $this->assertStringNotContainsString('Tujuan:', $premium);
        $topup = $formatter->order($this->order(['purpose' => 'topup']), 'success', 'https://shop.example');
        $this->assertStringContainsString('Saldo berhasil ditambahkan', $topup);
        $this->assertStringContainsString('Total pembayaran: *Rp10.123*', $topup);
        $this->assertStringContainsString('Saldo masuk: *Rp10.000*', $topup);
        $this->assertStringNotContainsString('Tujuan:', $topup);
    }

    public function test_refund_is_only_claimed_after_recorded_refund_and_guest_is_directed_to_the_store(): void
    {
        $formatter = app(CustomerNoticeText::class);
        $pendingRefund = $formatter->order($this->order(), 'failed', 'https://shop.example');
        $this->assertStringNotContainsString('Dikembalikan ke saldo', $pendingRefund);
        $refunded = $formatter->order($this->order(['refunded_at' => '2026-10-27 03:24:01']), 'failed', 'https://shop.example');
        $this->assertStringContainsString('Dikembalikan ke saldo toko: *Rp10.123*', $refunded);
        $guest = $formatter->order($this->order(['customer_id' => null]), 'failed', 'https://shop.example');
        $this->assertStringContainsString('Hubungi toko', $guest);
        $this->assertStringContainsString('refund manual', $guest);
    }

    public function test_cancellation_is_distinct_from_expiry_and_does_not_claim_payment_or_refund(): void
    {
        $formatter = app(CustomerNoticeText::class);
        foreach ([['cancelled_by_customer' => true], ['cancelled_by_owner' => 123]] as $flags) {
            $order = $this->order(['snapshot' => ['name' => 'Pulsa', ...$flags], 'status' => 'expired', 'payment_status' => 'unpaid', 'refunded_at' => null]);
            $text = $formatter->order($order, 'expired', 'https://shop.example');
            $this->assertStringContainsString('Pesanan dibatalkan', $text);
            $this->assertStringContainsString('Jangan membayar', $text);
            $this->assertStringNotContainsString('Pembayaran kedaluwarsa', $text);
            $this->assertStringNotContainsString('Dikembalikan ke saldo', $text);
        }
        $text = $formatter->order($this->order(['status' => 'expired']), 'expired', 'https://shop.example');
        $this->assertStringContainsString('Pembayaran kedaluwarsa', $text);
    }

    public function test_sandbox_is_labelled_and_result_preserves_raw_credentials_before_the_footer(): void
    {
        $formatter = app(CustomerNoticeText::class);
        $body = $formatter->order($this->order(['is_sandbox' => true]), 'success', 'https://shop.example');
        $this->assertStringContainsString('Mode pengujian · bukan transaksi nyata', $body);
        $result = "Email: demo@example.test\nPassword: a*_b\\nc";
        $message = $formatter->withResult($body, $result);
        $this->assertStringContainsString("*Hasil pesanan*\n".$result, $message);
        $this->assertStringEndsWith('_Powered by DigitalToko_', $message);
    }
}
