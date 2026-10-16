<?php

namespace App\Http\Controllers;

use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\Store;
use App\Support\CatalogAccess;
use App\Support\ListQuery;
use App\Support\StorefrontDesign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StoreDesignController extends Controller
{
    private function authorize(Request $request, Store $store): void
    {
        CatalogAccess::authorize($request, $store, 'web.manage');
        abort_unless($store->supportsWeb(), 403);
    }

    public function products(Request $request, Store $store): JsonResponse
    {
        $this->authorize($request, $store);
        $list = new ListQuery($request, [], 10);
        $page = $list->paginate($list->apply(CatalogProduct::where('store_id', $store->id)->where('active', true), ['name', 'code', 'category'])
            ->select(['id', 'name', 'code', 'category', 'sale_price', 'active'])->orderBy('name')->orderBy('id'));

        return response()->json(['items' => $page->items(), 'pagination' => ListQuery::meta($page)])->header('Cache-Control', 'private, no-store');
    }

    public function categories(Request $request, Store $store): JsonResponse
    {
        $this->authorize($request, $store);
        $list = new ListQuery($request, [], 10);
        if ($request->boolean('ids')) {
            $query = CatalogCategory::where('store_id', $store->id)->with('service:id,name')->whereHas('products', fn ($query) => $query->where('active', true));
            $page = $list->paginate($list->apply($query, ['name'])->select(['id', 'name', 'service_id'])->orderBy('name')->orderBy('id'));

            return response()->json(['items' => $page->getCollection()->map(fn ($category) => [...$category->only(['id', 'name']), 'code' => $category->service->name]),
                'pagination' => ListQuery::meta($page)])->header('Cache-Control', 'private, no-store');
        }
        $page = $list->paginate($list->apply(CatalogProduct::where('store_id', $store->id)->where('active', true), ['category'])
            ->selectRaw('category as id, category as name')->groupBy('category')->orderBy('category'));

        return response()->json(['items' => $page->items(), 'pagination' => ListQuery::meta($page)])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, Store $store, StorefrontDesign $designs): RedirectResponse
    {
        $this->authorize($request, $store);
        if (str_contains((string) $request->header('Content-Type'), 'multipart/form-data') && is_array($request->input('design'))) {
            $incoming = $request->input('design');
            foreach (['banners', 'flash_product_ids', 'trending_product_ids', 'trending_category_ids'] as $key) {
                $incoming[$key] ??= [];
            }
            $request->merge(['design' => $incoming]);
        }
        $image = function (string $attribute, mixed $value, \Closure $fail) use ($store): void {
            if ($value === null || $value === '') {
                return;
            }
            if (is_string($value) && (filter_var($value, FILTER_VALIDATE_URL) && parse_url($value, PHP_URL_SCHEME) === 'https')) {
                return;
            }
            if (is_string($value) && preg_match('#^/storefront-media/'.preg_quote($store->id, '#').'/([a-zA-Z0-9_-]{20,80}\.(?:jpg|jpeg|png|webp))$#', $value, $match)
                && Storage::disk('local')->exists('storefront/'.$store->id.'/'.$match[1])) {
                return;
            }
            $fail('Gunakan gambar HTTPS atau unggahan milik toko ini.');
        };
        $link = function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || preg_match('/[\\\\\x00-\x20\x7f]/', $value)) {
                $fail('Tautan tidak valid. Gunakan URL HTTPS atau path toko.');

                return;
            }
            if (preg_match('~^(?:\#[a-zA-Z0-9_-]+|/(?!/)[a-zA-Z0-9/?=&%._#-]*)$~', $value)) {
                return;
            }
            if (filter_var($value, FILTER_VALIDATE_URL) && parse_url($value, PHP_URL_SCHEME) === 'https' && ! parse_url($value, PHP_URL_USER) && ! parse_url($value, PHP_URL_PASS)) {
                return;
            }
            $fail('Gunakan URL HTTPS atau path toko seperti / dan #catalog.');
        };
        $ids = ['required', 'uuid', 'distinct', Rule::exists('catalog_products', 'id')->where('store_id', $store->id)];
        $file = 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096|dimensions:max_width=6000,max_height=6000';
        $data = $request->validate([
            'revision' => 'required|integer|min:0',
            'design' => 'required|array:accent,logo,colors,carousel,welcome_ad,customer_service,banners,flash_product_ids,flash_starts_at,flash_ends_at,trending_mode,trending_product_ids,trending_category_ids,sections',
            'design.trending_category_ids' => 'sometimes|array|max:12',
            'design.trending_category_ids.*' => ['required', 'uuid', 'distinct', Rule::exists('catalog_categories', 'id')->where('store_id', $store->id)],
            'design.accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'design.logo' => ['nullable', 'string', 'max:1000', $image],
            'design.welcome_ad' => 'sometimes|array:enabled,title,message,image,href,button|required_array_keys:enabled,title',
            'design.welcome_ad.enabled' => 'required_with:design.welcome_ad|boolean',
            'design.welcome_ad.title' => 'required_with:design.welcome_ad|string|max:100',
            'design.welcome_ad.message' => 'nullable|string|max:500',
            'design.welcome_ad.image' => ['nullable', 'string', 'max:1000', $image],
            'design.welcome_ad.href' => ['nullable', 'string', 'max:1000', $link],
            'design.welcome_ad.button' => 'nullable|string|max:40',
            'design.customer_service' => 'sometimes|array:enabled,phone,label,message|required_array_keys:enabled,label',
            'design.customer_service.enabled' => 'required_with:design.customer_service|boolean',
            'design.customer_service.phone' => ['nullable', 'string', 'regex:/^(?:\+?62|0)8[0-9]{7,13}$/'],
            'design.customer_service.label' => 'required_with:design.customer_service|string|max:40',
            'design.customer_service.message' => 'nullable|string|max:500',
            'design.colors' => 'sometimes|array:secondary,background,surface,text,muted,border|required_array_keys:secondary,background,surface,text,muted,border',
            'design.colors.*' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'design.carousel' => 'sometimes|array:autoplay,interval|required_array_keys:autoplay,interval',
            'design.carousel.autoplay' => 'required_with:design.carousel|boolean',
            'design.carousel.interval' => 'required_with:design.carousel|integer|between:3,30',
            'design.banners' => 'present|array|max:5', 'design.banners.*' => 'required|array:id,title,subtitle,image,href,button,enabled,overlay,link_type,category',
            'design.banners.*.id' => ['required', 'string', 'distinct', 'regex:/^[a-zA-Z0-9_-]{1,80}$/'],
            'design.banners.*.title' => 'required|string|max:100', 'design.banners.*.subtitle' => 'nullable|string|max:200',
            'design.banners.*.image' => ['nullable', 'string', 'max:1000', $image],
            'design.banners.*.link_type' => 'sometimes|required|in:url,category,none',
            'design.banners.*.category' => ['nullable', 'string', 'max:100', Rule::exists('catalog_products', 'category')->where('store_id', $store->id)->where('active', true)],
            'design.banners.*.href' => ['nullable', 'string', 'max:1000', $link],
            'design.banners.*.button' => 'nullable|string|max:40', 'design.banners.*.enabled' => 'required|boolean', 'design.banners.*.overlay' => 'required|boolean',
            'design.flash_product_ids' => 'present|array|max:12', 'design.flash_product_ids.*' => $ids,
            'design.flash_starts_at' => 'nullable|date_format:Y-m-d\TH:i:sP',
            'design.flash_ends_at' => ['nullable', 'date_format:Y-m-d\TH:i:sP', Rule::when($request->filled('design.flash_starts_at'), 'after:design.flash_starts_at')],
            'design.trending_mode' => 'required|in:automatic,manual', 'design.trending_product_ids' => 'present|array|max:12', 'design.trending_product_ids.*' => $ids,
            'design.sections' => 'required|array|size:7', 'design.sections.*' => 'required|array:key,enabled,title',
            'design.sections.*.key' => ['required', 'distinct', Rule::in(StorefrontDesign::SECTIONS)],
            'design.sections.*.enabled' => 'required|boolean', 'design.sections.*.title' => 'nullable|string|max:60',
            'ad_file' => $file, 'logo_file' => $file, 'banner_files' => 'sometimes|array|max:5', 'banner_files.*' => $file,
        ]);
        if (($data['design']['customer_service']['enabled'] ?? false) && empty($data['design']['customer_service']['phone'])) {
            throw ValidationException::withMessages(['design.customer_service.phone' => 'Isi nomor WhatsApp customer service.']);
        }
        if (! empty($data['design']['welcome_ad']['href']) && empty(trim($data['design']['welcome_ad']['button'] ?? ''))) {
            throw ValidationException::withMessages(['design.welcome_ad.button' => 'Isi teks tombol iklan atau kosongkan tujuan tautannya.']);
        }
        foreach ($data['design']['banners'] as $index => &$banner) {
            $type = $banner['link_type'] ?? 'url';
            $banner['link_type'] = $type;
            if ($type === 'category' && empty($banner['category'])) {
                throw ValidationException::withMessages(['design.banners.'.$index.'.category' => 'Pilih kategori dari katalog toko.']);
            }
            if ($type === 'url' && empty($banner['href'])) {
                throw ValidationException::withMessages(['design.banners.'.$index.'.href' => 'Isi tujuan tautan banner.']);
            }
            if ($type !== 'none' && empty(trim($banner['button'] ?? ''))) {
                throw ValidationException::withMessages(['design.banners.'.$index.'.button' => 'Isi teks tombol atau label tautan.']);
            }
            $banner['href'] = match ($type) {
                'none' => '',
                'category' => '/?category='.rawurlencode($banner['category']).'#catalog',
                default => $banner['href'],
            };
            $banner['category'] = $type === 'category' ? $banner['category'] : null;
        }
        unset($banner);
        foreach ($data['design']['sections'] as $section) {
            if ($section['key'] === 'catalog' && ! $section['enabled']) {
                throw ValidationException::withMessages(['design.sections' => 'Katalog utama harus tetap ditampilkan.']);
            }
        }
        $newFiles = [];
        $oldFiles = [];
        try {
            DB::transaction(function () use ($store, $request, $data, $designs, &$newFiles, &$oldFiles) {
                $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
                $branding = $locked->branding ?? [];
                if ((int) ($branding['design_revision'] ?? 0) !== (int) $data['revision']) {
                    throw ValidationException::withMessages(['revision' => 'Desain telah diubah pengguna lain. Muat ulang halaman sebelum menyimpan kembali.']);
                }
                $oldFiles = $designs->assets($branding['design'] ?? [], $store->id);
                $design = [...$designs->engagementDefaults(), ...$data['design']];
                $design['welcome_ad']['enabled'] = (bool) $design['welcome_ad']['enabled'];
                $design['customer_service']['enabled'] = (bool) $design['customer_service']['enabled'];
                $design['carousel'] = ['autoplay' => (bool) ($design['carousel']['autoplay'] ?? true), 'interval' => (int) ($design['carousel']['interval'] ?? 5)];
                $design['sections'] = array_map(fn ($section) => [...$section, 'enabled' => (bool) $section['enabled']], $design['sections']);
                $saveFile = function ($file) use ($store, &$newFiles): string {
                    $path = $file->store('storefront/'.$store->id, 'local');
                    if (! is_string($path)) {
                        throw new \RuntimeException('Gambar tidak dapat disimpan.');
                    }
                    $newFiles[] = $path;

                    return '/storefront-media/'.$store->id.'/'.basename($path);
                };
                if ($request->hasFile('ad_file')) {
                    $design['welcome_ad']['image'] = $saveFile($request->file('ad_file'));
                }
                if ($request->hasFile('logo_file')) {
                    $design['logo'] = $saveFile($request->file('logo_file'));
                }
                foreach ($design['banners'] as &$banner) {
                    $banner['enabled'] = (bool) $banner['enabled'];
                    $banner['overlay'] = (bool) $banner['overlay'];
                    if ($request->hasFile('banner_files.'.$banner['id'])) {
                        $banner['image'] = $saveFile($request->file('banner_files.'.$banner['id']));
                    }
                }
                unset($banner);
                $branding['design'] = $design;
                $branding['design_revision'] = $data['revision'] + 1;
                $locked->update(['branding' => $branding]);
                $oldFiles = array_diff($oldFiles, $designs->assets($design, $store->id));
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($newFiles);
            throw $error;
        }
        $categoryFiles = $designs->assets(['banners' => array_map(fn ($image) => ['image' => $image], $store->fresh()->branding['category_images'] ?? [])], $store->id);
        Storage::disk('local')->delete(array_diff($oldFiles, $categoryFiles));

        return back()->with('success', 'Desain web toko berhasil diterapkan.');
    }

    public function asset(Store $store, string $asset): StreamedResponse
    {
        $path = 'storefront/'.$store->id.'/'.$asset;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'public, max-age=86400, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }
}
