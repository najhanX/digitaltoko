<?php

namespace Tests\Unit;

use App\Services\DigiflazzNetwork;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DigiflazzNetworkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'suppliers.digiflazz_outbound_ip' => null]);
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_rejected_ip_takes_precedence_without_exposing_raw_supplier_message(): void
    {
        config(['suppliers.digiflazz_outbound_ip' => '1.1.1.1']);
        $message = app(DigiflazzNetwork::class)->whitelistMessage(true, 'IP Anda (8.8.4.4) ditolak SECRET-KEY');
        $this->assertStringContainsString('Development (RC 45)', $message);
        $this->assertStringContainsString('Tambahkan IP 8.8.4.4', $message);
        $this->assertStringNotContainsString('SECRET-KEY', $message);
        Http::assertNothingSent();
    }

    public function test_configured_public_ip_avoids_detection_requests(): void
    {
        config(['suppliers.digiflazz_outbound_ip' => '1.1.1.1']);
        $message = app(DigiflazzNetwork::class)->whitelistMessage(false);
        $this->assertStringContainsString('Production (RC 45)', $message);
        $this->assertStringContainsString('Tambahkan IP 1.1.1.1', $message);
        Http::assertNothingSent();
    }

    public function test_public_ip_detection_is_cached_and_private_ips_are_ignored(): void
    {
        config(['suppliers.digiflazz_outbound_ip' => '127.0.0.1']);
        Http::fake(['api.ipify.org' => Http::response("8.8.8.8\n")]);
        $diagnostic = app(DigiflazzNetwork::class);
        $message = $diagnostic->whitelistMessage(true, 'IP 192.168.1.1');
        $this->assertStringContainsString('IP publik keluar server terdeteksi: 8.8.8.8', $message);
        $this->assertStringNotContainsString('192.168.1.1', $message);
        $diagnostic->whitelistMessage(false);
        Http::assertSentCount(1);
    }

    public function test_failed_detection_does_not_throw_or_guess_an_ip(): void
    {
        Http::fake(['api.ipify.org' => Http::response('Layanan tidak tersedia', 503)]);
        $message = app(DigiflazzNetwork::class)->whitelistMessage(true);
        $this->assertStringContainsString('Development (RC 45)', $message);
        $this->assertStringContainsString('belum dapat dideteksi', $message);
        $this->assertStringNotContainsString('Layanan tidak tersedia', $message);
        app(DigiflazzNetwork::class)->whitelistMessage(true);
        Http::assertSentCount(1);
    }
}
