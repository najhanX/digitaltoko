<?php

namespace App\Http\Controllers;

use App\Models\ArticleAsset;
use App\Models\Store;
use App\Models\StoreArticle;
use App\Services\ArticleContent;
use App\Support\CatalogAccess;
use App\Support\ListQuery;
use App\Support\PlatformHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StoreArticleController extends Controller
{
    public function index(Request $request, Store $store): Response
    {
        $role = CatalogAccess::authorize($request, $store, 'articles.view');
        abort_unless($store->supportsWeb(), 403);
        $list = new ListQuery($request, ['status' => ['draft', 'published']], 10);
        $query = StoreArticle::where('store_id', $store->id)->select(['id', 'title', 'slug', 'author_name', 'excerpt', 'cover', 'tags', 'status', 'revision', 'published_at', 'updated_at']);
        $page = $list->paginate($list->apply($query, ['title', 'author_name', 'excerpt'], ['status' => 'status'])->orderByDesc('updated_at')->orderBy('id'));

        return Inertia::render('Owner/Articles', ['role' => $role, 'store' => $store->only(['id', 'name', 'status']),
            'storeUrl' => app(PlatformHost::class)->storeUrl($store), 'canManage' => CatalogAccess::has($request, $role, 'articles.manage'),
            'items' => $page->items(), 'filters' => $list->filters, 'pagination' => ListQuery::meta($page)]);
    }

    public function show(Request $request, Store $store, StoreArticle $article): JsonResponse
    {
        CatalogAccess::authorize($request, $store, 'articles.view');
        abort_unless($store->supportsWeb() && $article->store_id === $store->id, 404);

        return response()->json(['article' => $article->only(['id', 'title', 'excerpt', 'cover', 'tags', 'status', 'content', 'revision'])])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, Store $store, ArticleContent $content, ?StoreArticle $article = null): RedirectResponse
    {
        $role = CatalogAccess::authorize($request, $store, 'articles.manage');
        abort_unless($store->supportsWeb(), 403);
        abort_if($article && $article->store_id !== $store->id, 404);
        CatalogAccess::operational($store);
        $data = $request->validate(['title' => 'required|string|max:180', 'excerpt' => 'nullable|string|max:500',
            'cover' => 'nullable|string|max:200', 'content' => 'required|array', 'tags' => 'present|array|max:8',
            'tags.*' => 'required|string|max:30|regex:/^[\pL\pN _-]+$/u', 'status' => 'required|in:draft,published',
            'revision' => $article ? 'required|integer|min:1' : 'nullable|integer'], [
                'title.required' => 'Judul artikel wajib diisi.', 'title.max' => 'Judul artikel maksimal 180 karakter.',
                'excerpt.max' => 'Ringkasan artikel maksimal 500 karakter.',
                'tags.max' => 'Gunakan maksimal 8 topik artikel.', 'tags.*.max' => 'Setiap topik maksimal 30 karakter.',
                'tags.*.regex' => 'Topik hanya boleh berisi huruf, angka, spasi, garis bawah, atau tanda hubung. Pisahkan topik dengan koma.',
                'content.required' => 'Isi artikel wajib diisi.', 'content.array' => 'Isi artikel tidak terbaca. Salin kembali melalui editor.',
            ]);
        $data['title'] = trim($data['title']);
        if ($data['title'] === '') {
            throw ValidationException::withMessages(['title' => 'Judul artikel wajib diisi.']);
        }
        $document = $content->normalize($data['content'], $store);
        $plain = trim($content->text($document));
        $assetIds = $content->assetIds($document, $store);
        if (! empty($data['cover'])) {
            $assetIds[] = $content->assetId($data['cover'], $store);
        }
        $assetIds = array_values(array_unique($assetIds));
        if ($data['status'] === 'published' && $plain === '') {
            throw ValidationException::withMessages(['content' => 'Tambahkan isi artikel sebelum menerbitkan.']);
        }
        DB::transaction(function () use ($request, $store, $article, $data, $document, $plain, $assetIds, $role) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            CatalogAccess::operational($locked);
            abort_unless($locked->supportsWeb(), 403);
            if ($article) {
                $article = StoreArticle::where('store_id', $locked->id)->whereKey($article->id)->lockForUpdate()->firstOrFail();
                if ($article->revision !== (int) $data['revision']) {
                    throw ValidationException::withMessages(['article' => 'Artikel telah diubah oleh pengguna lain. Tutup editor dan buka kembali untuk memuat versi terbaru.']);
                }
            }
            $assets = ArticleAsset::where('store_id', $store->id)->whereIn('id', $assetIds)->lockForUpdate()->get();
            if ($assets->count() !== count($assetIds) || $assets->contains(fn ($asset) => $asset->article_id && $asset->article_id !== $article?->id)) {
                throw ValidationException::withMessages(['content' => 'Gambar sudah tidak tersedia atau bukan milik artikel toko ini. Unggah kembali gambarnya.']);
            }
            if (! $article) {
                $slug = Str::slug($data['title']) ?: 'artikel';
                $slug = substr($slug, 0, 180);
                $original = $slug;
                $number = 2;
                while (StoreArticle::where('store_id', $store->id)->where('slug', $slug)->exists()) {
                    $slug = $original.'-'.$number++;
                }
                $article = new StoreArticle(['store_id' => $store->id, 'slug' => $slug,
                    'author_name' => mb_substr($request->user($role === 'owner' ? 'web' : 'staff')->name, 0, 100)]);
            }
            $article->fill(['title' => $data['title'], 'excerpt' => trim($data['excerpt'] ?? '') ?: Str::limit($plain, 180),
                'cover' => $data['cover'] ?? null, 'content' => $document,
                'tags' => array_values(array_unique(array_map(fn ($tag) => mb_strtolower(str_replace(' ', '-', trim($tag))), $data['tags']))),
                'status' => $data['status'], 'revision' => ($article->revision ?? 0) + 1,
                'published_at' => $data['status'] === 'published' ? ($article->published_at ?? now()) : $article->published_at])->save();
            ArticleAsset::whereIn('id', $assets->modelKeys())->update(['article_id' => $article->id]);
            $unused = ArticleAsset::where('store_id', $store->id)->where('article_id', $article->id)->whereNotIn('id', $assetIds)->get();
            ArticleAsset::whereIn('id', $unused->modelKeys())->delete();
            DB::afterCommit(fn () => Storage::disk('local')->delete($unused->pluck('path')->all()));
        });

        return back()->with('success', $data['status'] === 'published' ? 'Artikel berhasil diterbitkan.' : 'Draf artikel berhasil disimpan.');
    }

    public function upload(Request $request, Store $store): JsonResponse
    {
        CatalogAccess::authorize($request, $store, 'articles.manage');
        abort_unless($store->supportsWeb(), 403);
        CatalogAccess::operational($store);
        $request->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=6000,max_height=6000']);
        $path = $request->file('image')->store('articles/'.$store->id, 'local');
        abort_unless(is_string($path), 500);
        try {
            $asset = DB::transaction(function () use ($store, $path) {
                CatalogAccess::operational(Store::whereKey($store->id)->lockForUpdate()->firstOrFail());

                return ArticleAsset::create(['store_id' => $store->id, 'path' => $path]);
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return response()->json(['url' => $asset->url()], 201)->header('Cache-Control', 'private, no-store');
    }

    public function delete(Request $request, Store $store, StoreArticle $article): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'articles.manage');
        abort_unless($store->supportsWeb() && $article->store_id === $store->id, 404);
        DB::transaction(function () use ($store, $article) {
            CatalogAccess::operational(Store::whereKey($store->id)->lockForUpdate()->firstOrFail());
            $article = StoreArticle::whereKey($article->id)->lockForUpdate()->firstOrFail();
            $paths = ArticleAsset::where('article_id', $article->id)->pluck('path')->all();
            $article->delete();
            DB::afterCommit(fn () => Storage::disk('local')->delete($paths));
        });

        return back()->with('success', 'Artikel dan gambar yang digunakan berhasil dihapus.');
    }
}
