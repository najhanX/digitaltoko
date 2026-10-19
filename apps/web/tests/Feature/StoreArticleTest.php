<?php

namespace Tests\Feature;

use App\Models\ArticleAsset;
use App\Models\PanelNotification;
use App\Models\Store;
use App\Models\StoreArticle;
use App\Models\StoreStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StoreArticleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false]);
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function shop(string $template = 'nebula'): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved'])->id,
            'slug' => 'article-'.Str::lower(Str::random(8)), 'name' => 'Article Shop', 'category' => 'full', 'plan' => 'galaxy', 'template' => $template, 'status' => 'active', 'expires_at' => now()->addMonth()]);
    }

    private function data(array $replace = []): array
    {
        return [...['title' => 'Tips Belanja Digital', 'excerpt' => '', 'cover' => null, 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Panduan produk digital.']]]]], 'tags' => ['Tips', 'produk digital'], 'status' => 'draft'], ...$replace];
    }

    private function url(Store $store): string
    {
        return 'http://localhost/stores/'.$store->id.'/articles';
    }

    private function publicUrl(Store $store, string $suffix = ''): string
    {
        return 'http://'.$store->slug.'.localhost/artikel'.$suffix;
    }

    private function image(Store $store): ArticleAsset
    {
        $file = UploadedFile::fake()->createWithContent('banner.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jMhkAAAAASUVORK5CYII='));
        $response = $this->postJson($this->url($store).'/images', ['image' => $file])->assertCreated();
        $asset = ArticleAsset::findOrFail(basename($response->json('url')));
        $this->assertSame($asset->url(), $response->json('url'));

        return $asset;
    }

    public function test_draft_publication_and_return_to_draft_preserve_author_and_stable_slug_with_revision_protection(): void
    {
        $store = $this->shop();
        $this->actingAs($store->owner)->post($this->url($store), $this->data())->assertRedirect()->assertSessionHasNoErrors();
        $article = StoreArticle::firstOrFail();
        $this->assertSame($store->owner->name, $article->author_name);
        $this->assertSame(['tips', 'produk-digital'], $article->tags);
        $this->assertNull($article->published_at);
        $this->get($this->publicUrl($store, '/'.$article->slug))->assertNotFound();
        $this->post($this->url($store).'/'.$article->id, $this->data(['title' => 'Judul diperbarui', 'status' => 'published', 'revision' => 1, 'author_name' => 'Nama palsu']))->assertRedirect()->assertSessionHasNoErrors();
        $article->refresh();
        $this->assertSame(2, $article->revision);
        $this->assertSame('tips-belanja-digital', $article->slug);
        $this->assertSame($store->owner->name, $article->author_name);
        $this->assertNotNull($article->published_at);
        $this->get($this->publicUrl($store, '/'.$article->slug))->assertInertia(fn (Assert $page) => $page->component('Storefront/Article')->where('article.title', 'Judul diperbarui')->where('article.content.content.0.content.0.text', 'Panduan produk digital.')->where('shareUrl', $this->publicUrl($store, '/'.$article->slug)));
        $this->post($this->url($store).'/'.$article->id, $this->data(['revision' => 1]))->assertSessionHasErrors('article');
        $this->assertSame('published', $article->fresh()->status);
        $this->post($this->url($store).'/'.$article->id, $this->data(['revision' => 2]))->assertRedirect()->assertSessionHasNoErrors();
        $this->get($this->publicUrl($store, '/'.$article->slug))->assertNotFound();
    }

    public function test_images_are_private_until_published_and_cannot_be_used_or_read_by_another_store(): void
    {
        $store = $this->shop();
        $other = $this->shop();
        $this->actingAs($store->owner);
        $image = $this->image($store);
        $this->get('http://localhost'.$image->url())->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get('http://'.$store->slug.'.localhost'.$image->url())->assertNotFound();
        $body = $this->data();
        $body['cover'] = $image->url();
        $body['content']['content'][] = ['type' => 'image', 'attrs' => ['src' => $image->url(), 'alt' => 'Contoh', 'onerror' => 'alert(1)']];
        $this->post($this->url($store), $body)->assertRedirect()->assertSessionHasNoErrors();
        $article = StoreArticle::firstOrFail();
        $this->assertArrayNotHasKey('onerror', $article->content['content'][1]['attrs']);
        $this->get('http://'.$store->slug.'.localhost'.$image->url())->assertNotFound();
        $this->actingAs($other->owner)->post($this->url($other), $this->data(['cover' => $image->url()]))->assertSessionHasErrors('content');
        $this->get('http://localhost'.$image->url())->assertForbidden();
        $this->actingAs($store->owner)->post($this->url($store).'/'.$article->id, [...$body, 'status' => 'published', 'revision' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('http://'.$store->slug.'.localhost'.$image->url())->assertOk();
        $this->get('http://'.$other->slug.'.localhost'.$image->url())->assertNotFound();
        $this->post($this->url($store), [...$body, 'title' => 'Artikel berbeda'])->assertSessionHasErrors('content');
        $this->post($this->url($store).'/'.$article->id, $this->data(['revision' => 2]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('article_assets', ['id' => $image->id]);
        Storage::disk('local')->assertMissing($image->path);
        $this->get('http://'.$store->slug.'.localhost'.$image->url())->assertNotFound();
    }

    public function test_owner_and_staff_permissions_are_store_scoped_and_bot_only_plans_cannot_publish(): void
    {
        $store = $this->shop();
        $other = $this->shop();
        $this->actingAs($store->owner)->post($this->url($store), $this->data())->assertRedirect()->assertSessionHasNoErrors();
        $article = StoreArticle::firstOrFail();
        $this->actingAs($other->owner)->get($this->url($store))->assertForbidden();
        $this->post($this->url($other).'/'.$article->id, $this->data(['revision' => 1]))->assertNotFound();
        $this->delete($this->url($other).'/'.$article->id)->assertNotFound();
        auth('web')->logout();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Editor', 'username' => 'writer', 'email' => 'writer@example.com', 'password' => 'long-enough-password', 'active' => true, 'permissions' => ['web.manage']]);
        $this->actingAs($staff, 'staff')->get($this->url($store))->assertForbidden();
        $staff->update(['permissions' => ['articles.view']]);
        $this->get($this->url($store))->assertInertia(fn (Assert $page) => $page->where('canManage', false)->where('role', 'staff'));
        $this->getJson($this->url($store).'/'.$article->id)->assertOk();
        $this->post($this->url($store), $this->data())->assertForbidden();
        $this->delete($this->url($store).'/'.$article->id)->assertForbidden();
        $staff->update(['permissions' => ['articles.manage']]);
        $this->get($this->url($store))->assertOk();
        $this->post($this->url($store), $this->data(['title' => 'Tulisan staff']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('store_articles', ['title' => 'Tulisan staff', 'author_name' => 'Editor']);
        $this->get($this->url($other))->assertForbidden();
        $store->update(['category' => 'bot']);
        $this->get($this->url($store))->assertForbidden();
        $this->post($this->url($store), $this->data())->assertForbidden();
        $this->get($this->publicUrl($store))->assertNotFound();
    }

    public function test_rich_text_roundtrip_preserves_formatting_and_rejects_executable_urls_unsupported_nodes_and_styles(): void
    {
        $store = $this->shop();
        $this->actingAs($store->owner);
        $body = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2, 'textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => 'Panduan']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Teks pilihan', 'marks' => [['type' => 'bold'], ['type' => 'textStyle', 'attrs' => ['fontSize' => '24px', 'lineHeight' => '1.5', 'color' => '#334455', 'fontFamily' => 'Georgia']]]], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'Lanjut', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/tips']]]]]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Satu']]]]]]],
        ]];
        $this->post($this->url($store), $this->data(['content' => $body]))->assertRedirect()->assertSessionHasNoErrors();
        $article = StoreArticle::firstOrFail();
        $this->assertSame('24px', $article->content['content'][1]['content'][0]['marks'][1]['attrs']['fontSize']);
        $this->assertSame('bulletList', $article->content['content'][2]['type']);
        foreach (['javascript:alert(1)', 'data:text/html,test', '//evil.example', 'https://good.example\\@evil.example'] as $href) {
            $bad = $this->data();
            $bad['content']['content'][0]['content'][0]['marks'] = [['type' => 'link', 'attrs' => ['href' => $href]]];
            $this->post($this->url($store), $bad)->assertSessionHasErrors('content');
        }
        $bad = $this->data();
        $bad['content']['content'][] = ['type' => 'script', 'text' => 'alert(1)'];
        $this->post($this->url($store), $bad)->assertSessionHasErrors('content');
        $bad = $this->data();
        $bad['content']['content'][0]['content'][0]['marks'] = [['type' => 'textStyle', 'attrs' => ['fontSize' => '999px', 'color' => 'url(https://evil.example)']]];
        $this->post($this->url($store), $bad)->assertSessionHasErrors('content');
        $this->assertDatabaseCount('store_articles', 1);
        $this->postJson($this->url($store).'/images', ['image' => UploadedFile::fake()->createWithContent('bad.svg', '<svg><script>alert(1)</script></svg>')])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_spaces_around_links_and_formatted_text_survive_save_edit_and_publication(): void
    {
        $store = $this->shop();
        $this->actingAs($store->owner);
        $nodes = [
            ['type' => 'text', 'text' => 'Baca '],
            ['type' => 'text', 'text' => 'panduan', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/panduan']]]],
            ['type' => 'text', 'text' => ' untuk '],
            ['type' => 'text', 'text' => 'produk digital', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' '],
            ['type' => 'text', 'text' => 'pilihan', 'marks' => [['type' => 'italic']]],
            ['type' => 'text', 'text' => ' Anda.'],
        ];
        $data = $this->data(['title' => '  Panduan tautan  ', 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $nodes]]]]);
        $this->post($this->url($store), $data)->assertRedirect()->assertSessionHasNoErrors();
        $article = StoreArticle::where('store_id', $store->id)->firstOrFail();
        $this->assertSame('Panduan tautan', $article->title);
        $this->assertSame(array_column($nodes, 'text'), array_column($article->content['content'][0]['content'], 'text'));
        $this->getJson($this->url($store).'/'.$article->id)->assertOk()
            ->assertJsonPath('article.content.content.0.content.0.text', 'Baca ')
            ->assertJsonPath('article.content.content.0.content.4.text', ' ');
        $this->postJson($this->url($store).'/'.$article->id, [...$data, 'status' => 'published', 'revision' => $article->revision])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->get($this->publicUrl($store, '/'.$article->slug))->assertInertia(fn (Assert $page) => $page
            ->where('article.content.content.0.content.0.text', 'Baca ')
            ->where('article.content.content.0.content.2.text', ' untuk ')
            ->where('article.content.content.0.content.4.text', ' ')
            ->where('article.content.content.0.content.6.text', ' Anda.'));
    }

    public function test_safe_pasted_text_formats_are_normalized_before_draft_and_publication(): void
    {
        $store = $this->shop();
        $this->actingAs($store->owner);
        $data = $this->data();
        $data['content']['content'][0]['content'][0]['marks'] = [['type' => 'textStyle', 'attrs' => [
            'fontSize' => '12pt', 'color' => 'rgb(31, 41, 55)', 'backgroundColor' => '#fff',
            'fontFamily' => '"Arial", sans-serif', 'lineHeight' => '1.50',
        ]]];
        $this->post($this->url($store), $data)->assertRedirect()->assertSessionHasNoErrors();
        $article = StoreArticle::firstOrFail();
        $style = $article->content['content'][0]['content'][0]['marks'][0]['attrs'];
        $this->assertSame('16px', $style['fontSize']);
        $this->assertSame('#1f2937', $style['color']);
        $this->assertSame('#ffffff', $style['backgroundColor']);
        $this->assertSame('Arial', $style['fontFamily']);
        $this->assertSame('1.5', $style['lineHeight']);
        $this->post($this->url($store).'/'.$article->id, [...$data, 'status' => 'published', 'revision' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('published', $article->fresh()->status);
    }

    public function test_article_validation_notification_includes_the_specific_reason_without_article_content(): void
    {
        $store = $this->shop();
        $data = $this->data(['title' => '', 'password' => 'DO-NOT-LOG-THIS']);
        $this->actingAs($store->owner)->from($this->url($store))->post($this->url($store), $data)->assertSessionHasErrors('title');
        $notice = PanelNotification::latest('id')->firstOrFail();
        $this->assertSame('Judul artikel wajib diisi.', $notice->message);
        $this->assertStringNotContainsString('DO-NOT-LOG-THIS', $notice->toJson());
        $this->assertStringNotContainsString('Panduan produk digital.', $notice->toJson());
    }

    public function test_lists_are_paginated_searchable_and_only_contain_published_articles_for_the_current_store(): void
    {
        $store = $this->shop('aurora');
        $other = $this->shop('atlas');
        $this->actingAs($store->owner);
        for ($i = 0; $i < 13; $i++) {
            $this->post($this->url($store), $this->data(['title' => 'Artikel '.$i, 'status' => 'published', 'tags' => [$i % 2 ? 'promo' : 'tips']]))->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->post($this->url($store), $this->data(['title' => 'Rahasia Draft']))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($other->owner)->post($this->url($other), $this->data(['title' => 'Rahasia Toko Lain', 'status' => 'published']))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($store->owner)->get($this->url($store).'?status=draft')->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.title', 'Rahasia Draft')->missing('items.0.content'));
        $this->get($this->publicUrl($store, '?per_page=5&page=2'))->assertInertia(fn (Assert $page) => $page->component('Storefront/Articles')->has('articles', 5)->where('pagination.total', 13)->where('pagination.current_page', 2)->missing('articles.0.content'));
        $this->get($this->publicUrl($store, '?tag=promo'))->assertInertia(fn (Assert $page) => $page->where('pagination.total', 6));
        $this->get($this->publicUrl($store, '?q=%23promo'))->assertInertia(fn (Assert $page) => $page->where('pagination.total', 6));
        $this->get($this->publicUrl($store, '?q=Rahasia'))->assertInertia(fn (Assert $page) => $page->has('articles', 0));
        $this->get($this->publicUrl($store, '?q=Artikel%2012'))->assertInertia(fn (Assert $page) => $page->has('articles', 1)->where('articles.0.title', 'Artikel 12'));
        $store->update(['admin_suspended_at' => now()]);
        $this->get($this->publicUrl($store))->assertNotFound();
        $this->post($this->url($store), $this->data())->assertForbidden();
    }

    public function test_related_articles_prefer_the_same_topic_and_do_not_include_the_current_article_or_drafts(): void
    {
        $store = $this->shop('atlas');
        $this->actingAs($store->owner);
        $this->post($this->url($store), $this->data(['title' => 'Artikel Utama', 'status' => 'published']))->assertRedirect()->assertSessionHasNoErrors();
        $main = StoreArticle::firstOrFail();
        $this->post($this->url($store), $this->data(['title' => 'Rekomendasi', 'status' => 'published']))->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->url($store), $this->data(['title' => 'Belum terbit']))->assertRedirect()->assertSessionHasNoErrors();
        $this->get($this->publicUrl($store, '/'.$main->slug))->assertInertia(fn (Assert $page) => $page->has('related', 1)->where('related.0.title', 'Rekomendasi'));
    }

    public function test_unique_slugs_are_per_store_and_blank_articles_cannot_be_published(): void
    {
        $store = $this->shop();
        $this->actingAs($store->owner)->post($this->url($store), $this->data())->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->url($store), $this->data())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['tips-belanja-digital', 'tips-belanja-digital-2'], StoreArticle::pluck('slug')->all());
        $this->post($this->url($store), $this->data(['status' => 'published', 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]]))->assertSessionHasErrors('content');
        $other = $this->shop();
        $this->actingAs($other->owner)->post($this->url($other), $this->data())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('store_articles', ['store_id' => $other->id, 'slug' => 'tips-belanja-digital']);
    }

    public function test_deleting_articles_removes_their_files_and_cleanup_only_prunes_stale_unattached_uploads(): void
    {
        $store = $this->shop();
        $this->actingAs($store->owner);
        $attached = $this->image($store);
        $this->post($this->url($store), $this->data(['cover' => $attached->url()]))->assertRedirect()->assertSessionHasNoErrors();
        $article = StoreArticle::firstOrFail();
        $old = $this->image($store);
        $old->update(['created_at' => now()->subHours(25)]);
        $fresh = $this->image($store);
        $this->artisan('digitaltoko:prune-article-uploads')->assertSuccessful();
        $this->assertDatabaseMissing('article_assets', ['id' => $old->id]);
        Storage::disk('local')->assertMissing($old->path);
        Storage::disk('local')->assertExists($attached->path);
        Storage::disk('local')->assertExists($fresh->path);
        $this->delete($this->url($store).'/'.$article->id)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('store_articles', ['id' => $article->id]);
        Storage::disk('local')->assertMissing($attached->path);
        Storage::disk('local')->assertExists($fresh->path);
    }
}
