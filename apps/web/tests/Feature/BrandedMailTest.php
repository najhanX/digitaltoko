<?php

namespace Tests\Feature;

use App\Mail\CustomerOtpMail;
use App\Mail\OwnerOtpMail;
use App\Mail\StoreMessageMail;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrandedMailTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        return Store::create(['owner_id' => User::factory()->create()->id, 'name' => 'Toko Warna', 'slug' => 'toko-warna', 'template' => 'nebula', 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'branding' => ['design' => ['accent' => '#ffcc00', 'colors' => ['background' => '#111122', 'surface' => '#222233', 'text' => '#eeeeff']]]]);
    }

    public function test_platform_otp_has_logo_color_and_plain_text_alternative(): void
    {
        $mail = new OwnerOtpMail('123456');
        $html = $mail->render();
        $this->assertTrue(str_contains($html, 'cid:') || str_contains($html, 'data:image/'), 'Logo must be embedded in the email.');
        $this->assertStringContainsString('#4f46e5', $html);
        $this->assertStringContainsString('DigitalToko', $html);
        $this->assertStringContainsString('123456', $html);
        $this->assertSame('mail.otp-text', $mail->textView);
    }

    public function test_store_mail_uses_own_local_logo_and_theme_with_readable_otp(): void
    {
        Storage::fake('local');
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'mail.default' => 'smtp']);
        $store = $this->store();
        $filename = Str::random(30).'.png';
        Storage::disk('local')->put('storefront/'.$store->id.'/'.$filename, UploadedFile::fake()->image('logo.png', 120, 40)->getContent());
        $branding = $store->branding;
        $branding['design']['logo'] = '/storefront-media/'.$store->id.'/'.$filename;
        $store->update(['branding' => $branding]);
        $mail = (new CustomerOtpMail($store->name, '654321'))->forStore($store);
        $html = $mail->render();
        $this->assertTrue(str_contains($html, 'cid:') || str_contains($html, 'data:image/'), 'Logo must be embedded in the email.');
        $this->assertStringContainsString('#ffcc00', $html);
        $this->assertStringContainsString('#111122', $html);
        $this->assertStringContainsString('#222233', $html);
        $this->assertSame('#17213a', $mail->branding['on_primary']);
        $this->assertStringContainsString('Toko Warna', $html);
        Mail::fake();
        app(StoreMail::class)->send($store, 'test@example.test', new CustomerOtpMail($store->name, '123456'));
        Mail::assertSent(CustomerOtpMail::class, fn ($sent) => $sent->branding['name'] === 'Toko Warna' && $sent->branding['logo_path'] !== null);
    }

    public function test_notification_escapes_content_and_does_not_embed_another_store_logo(): void
    {
        $store = $this->store();
        $other = $store->replicate();
        $other->slug = 'other-brand';
        $other->save();
        $store->update(['branding' => ['design' => ['logo' => '/storefront-media/'.$other->id.'/'.str_repeat('x', 30).'.png']]]);
        $mail = (new StoreMessageMail($store->name, "Order berhasil\n<script>unsafe()</script>"))->forStore($store);
        $html = $mail->render();
        $this->assertNull($mail->branding['logo_path']);
        $this->assertNull($mail->branding['logo']);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Periksa pesanan', $html);
        $this->assertSame('mail.message-text', $mail->textView);
    }

    public function test_plain_text_alternative_preserves_credential_characters_without_html_entities(): void
    {
        $text = 'Password: A&B<123>"test';
        $sent = Mail::mailer('array')->to('test@example.test')->send(new StoreMessageMail('Toko & Uji', $text));
        $message = $sent->getSymfonySentMessage()->getOriginalMessage();
        $this->assertStringContainsString($text, $message->getTextBody());
        $this->assertStringNotContainsString($text, $message->getHtmlBody());
        $this->assertStringContainsString('A&amp;B&lt;123&gt;', $message->getHtmlBody());
    }
}
