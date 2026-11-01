<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TutorialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_library_is_public_and_index_only_exposes_summary_data(): void
    {
        $this->get('http://localhost/panduan')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Platform/Tutorials')->has('guides', 22)->where('guide', null)
            ->where('guides.0.slug', 'mulai')->missing('guides.0.steps')->missing('guides.0.image'));
        Http::assertNothingSent();
    }

    public function test_all_guides_have_valid_links_and_real_illustrations_and_can_be_opened_anonymously(): void
    {
        $guides = json_decode(file_get_contents(resource_path('js/tutorialCatalog.json')), true, 512, JSON_THROW_ON_ERROR);
        $slugs = array_column($guides, 'slug');
        $this->assertCount(count($slugs), array_unique($slugs));
        foreach ($guides as $guide) {
            $this->assertGreaterThanOrEqual(4, count($guide['steps']));
            $this->assertNotEmpty($guide['prerequisites']);
            $this->assertNotEmpty($guide['troubleshooting']);
            $this->assertStringStartsWith('/guides/', $guide['image']);
            $image = public_path($guide['image']);
            $this->assertFileExists($image);
            $size = getimagesize($image);
            $this->assertGreaterThan(1000, $size[0]);
            $this->assertGreaterThan(600, $size[1]);
            foreach ($guide['steps'] as $step) {
                $visual = $step['visual'];
                $this->assertNotEmpty($visual['caption']);
                $this->assertStringStartsWith('/guides/steps/', $visual['image']);
                $this->assertStringNotContainsString('..', $visual['image']);
                $this->assertFileExists(public_path($visual['image']));
                $this->assertGreaterThan(0, $visual['width']);
                $this->assertGreaterThan(0, $visual['height']);
                if (str_ends_with($visual['image'], '.svg')) {
                    $this->assertTrue($visual['illustration']);
                    $this->assertStringContainsString('ILUSTRASI', file_get_contents(public_path($visual['image'])));
                } else {
                    $imageSize = getimagesize(public_path($visual['image']));
                    $this->assertSame($visual['width'], $imageSize[0]);
                    $this->assertSame($visual['height'], $imageSize[1]);
                }
                $this->assertGreaterThanOrEqual(1, count($visual['markers']));
                $this->assertLessThanOrEqual(3, count($visual['markers']));
                foreach ($visual['markers'] as $marker) {
                    $this->assertNotEmpty($marker['label']);
                    foreach (['x', 'y', 'w', 'h'] as $coordinate) {
                        $this->assertGreaterThanOrEqual(0, $marker[$coordinate]);
                        $this->assertLessThanOrEqual(100, $marker[$coordinate]);
                    }
                }
            }
            foreach ($guide['related'] as $related) {
                $this->assertContains($related, $slugs);
                $this->assertNotSame($guide['slug'], $related);
            }
            $this->get('http://localhost/panduan/'.$guide['slug'])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Tutorials')->where('guide.slug', $guide['slug'])->has('guide.steps', count($guide['steps'])));
        }
        Http::assertNothingSent();
    }

    public function test_unknown_guides_and_tenant_hosts_do_not_expose_platform_tutorial_routes(): void
    {
        $this->get('http://localhost/panduan/tidak-ada')->assertNotFound();
        $this->get('http://unknown.localhost/panduan')->assertNotFound();
    }

    public function test_listener_guide_has_os_specific_commands_and_only_links_an_available_bundle(): void
    {
        config(['services.forwarder.bundle_path' => __DIR__.'/missing-installer.zip']);
        $this->get('http://localhost/panduan/aktifkan-listener')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('guide.download', null)->has('guide.steps', 12)
            ->where('guide.steps.6.commands.0.code', 'adb.exe devices')
            ->where('guide.steps.6.commands.1.code', '.\\adb.exe devices')
            ->where('guide.steps.6.commands.2.code', './adb devices')
            ->where('guide.steps.7.commands.0.code', 'adb.exe shell cmd notification allow_listener id.hansdev.forwarder/.ForwardListener'));
        $path = tempnam(sys_get_temp_dir(), 'forwarder-guide-');
        file_put_contents($path, 'ZIP-FIXTURE');
        try {
            config(['services.forwarder.bundle_path' => $path]);
            $this->get('http://localhost/panduan/aktifkan-listener')->assertInertia(fn (Assert $page) => $page
                ->where('guide.download.url', 'http://localhost/downloads/hansdev-forwarder'));
        } finally {
            unlink($path);
        }
    }
}
