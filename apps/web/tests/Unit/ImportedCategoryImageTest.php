<?php

namespace Tests\Unit;

use App\Services\ImportedCategoryImage;
use Tests\TestCase;

class ImportedCategoryImageTest extends TestCase
{
    public function test_brand_matching_handles_case_punctuation_and_whole_words_without_guessing_ambiguous_names(): void
    {
        $images = app(ImportedCategoryImage::class);
        $this->assertSame('/brand/catalog/telkomsel.svg', $images->resolve('TELKOMSEL - Paket Data'));
        $this->assertSame('/brand/catalog/xl.svg', $images->resolve('XTRA Combo Flex'));
        $this->assertSame('/brand/catalog/spotify.svg', $images->resolve('Spotify Premium'));
        $this->assertSame('/brand/catalog/valorant.svg', $images->resolve('Valorant Points'));
        foreach (['', 'VPN', 'Produk Bebas', 'XLARGE', 'AXISymmetric', 'Spotify + Telkomsel'] as $name) {
            $this->assertNull($images->resolve($name), $name);
        }
    }

    public function test_every_configured_logo_has_a_local_file_and_traceable_public_domain_source(): void
    {
        $sources = json_decode(file_get_contents(public_path('brand/catalog/sources.json')), true, flags: JSON_THROW_ON_ERROR);
        $sources = array_column($sources['assets'], null, 'asset');
        foreach (config('category_logos') as $logo) {
            $this->assertArrayHasKey($logo['asset'], $sources);
            $source = $sources[$logo['asset']];
            $this->assertContains($source['license'], ['Public domain (PD-textlogo)', 'CC0-1.0']);
            $this->assertStringStartsWith('https://commons.wikimedia.org/wiki/File:', $source['source_page']);
            $this->assertFileExists(public_path('brand/catalog/'.$logo['asset']));
            $this->assertSame($source['sha256'], hash_file('sha256', public_path('brand/catalog/'.$logo['asset'])));
        }
        config(['category_logos' => [['asset' => 'missing.svg', 'aliases' => ['missing']]]]);
        $this->assertNull(app(ImportedCategoryImage::class)->resolve('missing'));
    }
}
