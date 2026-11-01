<?php

namespace Tests\Unit;

use App\Services\QrisNotificationParser;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QrisNotificationParserTest extends TestCase
{
    public function test_templates_capture_only_the_selected_amount_and_optional_fields(): void
    {
        $parser = new QrisNotificationParser;
        $config = $parser->validate([...$parser->defaults(), 'rules' => [
            ['name' => 'Bank toko', 'active' => true, 'template' => 'Pembayaran Rp[nominal] dari [pengirim]. Ref [referensi]. Saldo Rp[abaikan]'],
        ]]);
        $result = $parser->parse($config, ['text' => 'Pembayaran Rp12.345,00 dari Budi. Ref BANK-123. Saldo Rp500.000']);
        $this->assertTrue($result['matched']);
        $this->assertSame(12345, $result['amount']);
        $this->assertSame('BANK-123', $result['fields']['referensi']);
        $this->assertSame('Budi', $result['fields']['pengirim']);
    }

    public function test_full_text_matching_blocks_wrong_punctuation_promos_and_outgoing_messages(): void
    {
        $parser = new QrisNotificationParser;
        $config = $parser->defaults();
        $this->assertTrue($parser->parse($config, ['text' => 'Rp292 diterima DANA Bisnis.'])['matched']);
        foreach (['Promo Rp292 diterima DANA Bisnis.', 'Rp292 diterima DANA Bisnis. tambahan', 'Rp292 berhasil dikirim.', 'Rp1.23 diterima DANA Bisnis.', 'Rp292,50 diterima DANA Bisnis.'] as $text) {
            $this->assertFalse($parser->parse($config, ['text' => $text])['matched'], $text);
        }
        $this->assertFalse($parser->parse($config, ['title' => 'Transaksi gagal', 'text' => 'Rp292 diterima DANA Bisnis.'])['matched']);
    }

    public function test_combined_title_multiline_whitespace_and_disabled_rules(): void
    {
        $parser = new QrisNotificationParser;
        $config = $parser->validate([...$parser->defaults(), 'field' => 'combined', 'rules' => [
            ['name' => 'Judul', 'active' => true, 'template' => "Pembayaran masuk\nRp[nominal] diterima."],
            ['name' => 'Nonaktif', 'active' => false, 'template' => 'Lainnya Rp[nominal] masuk.'],
        ]]);
        $this->assertSame(10000, $parser->parse($config, ['title' => 'PEMBAYARAN MASUK', 'text' => 'Rp10.000 diterima.'])['amount']);
        $this->assertFalse($parser->parse($config, ['text' => 'Lainnya Rp10.000 masuk.'])['matched']);
    }

    public function test_invalid_and_ambiguous_templates_fail_closed(): void
    {
        $parser = new QrisNotificationParser;
        foreach (['Rp[nominal] dan [nominal] diterima.', 'Pembayaran [unknown] diterima.', '[nominal]', 'Pembayaran Rp[nominal] [regex]'] as $template) {
            try {
                $parser->validate([...$parser->defaults(), 'rules' => [['name' => 'Invalid', 'active' => true, 'template' => $template]]]);
                $this->fail('Template must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('rules.0.template', $exception->errors());
            }
        }
        $config = $parser->validate([...$parser->defaults(), 'rules' => [
            ['name' => 'Pertama', 'active' => true, 'template' => 'Pembayaran Rp[nominal] diterima, Rp[abaikan] tercatat.'],
            ['name' => 'Kedua', 'active' => true, 'template' => 'Pembayaran Rp[abaikan] diterima, Rp[nominal] tercatat.'],
        ]]);
        $result = $parser->parse($config, ['text' => 'Pembayaran Rp10.000 diterima, Rp20.000 tercatat.']);
        $this->assertFalse($result['matched']);
        $this->assertStringContainsString('berbeda', $result['reason']);
    }
}
