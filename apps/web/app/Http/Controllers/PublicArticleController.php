<?php

namespace App\Http\Controllers;

use App\Models\ArticleAsset;
use App\Models\Store;
use App\Models\StoreArticle;
use App\Support\CatalogAccess;
use App\Support\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicArticleController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store && $store->supportsWeb() && $store->acceptsOrders(), 404);

        return $store;
    }

    public function index(Request $request): Response
    {
        $store = $this->store($request);
        $data = $request->validate(['tag' => 'sometimes|nullable|string|max:30|regex:/^[\pL\pN _-]+$/u']);
        $list = new ListQuery($request, [], 10);
        $list->filters['tag'] = $data['tag'] ?? '';
        $query = StoreArticle::where('store_id', $store->id)->published();
        if ($list->filters['tag'] !== '') {
            $query->whereJsonContains('tags', $list->filters['tag']);
        }
        if ($list->filters['q'] !== '') {
            $tag = mb_strtolower(preg_replace('/\s+/', '-', ltrim($list->filters['q'], '#')));
            $query->where(fn ($query) => $list->apply($query, ['title', 'excerpt', 'author_name'])->orWhereJsonContains('tags', $tag));
        }
        $page = $list->paginate($query->select(['id', 'title', 'slug', 'author_name', 'excerpt', 'cover', 'tags', 'published_at'])->orderByDesc('published_at')->orderBy('id'));
        $tags = StoreArticle::where('store_id', $store->id)->published()->latest('published_at')->limit(200)->pluck('tags')->flatten()->unique()->take(24)->values();

        return Inertia::render('Storefront/Articles', ['store' => $store->only(['id', 'name', 'slug', 'template', 'branding', 'status']),
            'articles' => $page->items(), 'filters' => $list->filters, 'pagination' => ListQuery::meta($page), 'topics' => $tags]);
    }

    public function show(Request $request, string $slug): Response
    {
        $store = $this->store($request);
        $article = StoreArticle::where('store_id', $store->id)->where('slug', $slug)->published()->firstOrFail();
        $related = StoreArticle::where('store_id', $store->id)->published()->whereKeyNot($article->id)
            ->select(['id', 'title', 'slug', 'author_name', 'excerpt', 'cover', 'tags', 'published_at'])->orderByDesc('published_at')->orderBy('id')->limit(4)->get();
        if ($article->tags) {
            $related = StoreArticle::where('store_id', $store->id)->published()->whereKeyNot($article->id)
                ->where(function ($query) use ($article) {
                    foreach ($article->tags as $tag) {
                        $query->orWhereJsonContains('tags', $tag);
                    }
                })->select(['id', 'title', 'slug', 'author_name', 'excerpt', 'cover', 'tags', 'published_at'])->orderByDesc('published_at')->orderBy('id')->limit(4)->get()->concat($related)->unique('id')->take(4)->values();
        }

        return Inertia::render('Storefront/Article', ['store' => $store->only(['id', 'name', 'slug', 'template', 'branding', 'status']),
            'article' => [...$article->summary(), 'content' => $article->content], 'related' => $related, 'shareUrl' => $request->url()]);
    }

    public function asset(Request $request, Store $store, ArticleAsset $asset): StreamedResponse
    {
        abort_unless($asset->store_id === $store->id, 404);
        $tenant = $request->attributes->get('store');
        if ($tenant) {
            abort_unless($tenant->id === $store->id && $store->supportsWeb() && $store->acceptsOrders()
                && $asset->article_id && StoreArticle::whereKey($asset->article_id)->where('store_id', $store->id)->published()->exists(), 404);
        } else {
            CatalogAccess::authorize($request, $store, 'articles.view');
        }
        abort_unless(Storage::disk('local')->exists($asset->path), 404);

        return Storage::disk('local')->response($asset->path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
