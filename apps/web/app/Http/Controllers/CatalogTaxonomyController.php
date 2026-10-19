<?php

namespace App\Http\Controllers;

use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\Store;
use App\Services\CatalogTaxonomy;
use App\Services\CategoryDestination;
use App\Services\GameIdentity;
use App\Services\QuickOrderCatalog;
use App\Services\StoreCatalog;
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
use Inertia\Inertia;
use Inertia\Response;

class CatalogTaxonomyController extends Controller
{
    private function selectedService(Request $request, Store $store): ?CatalogService
    {
        $id = $request->query('service_id');

        return is_string($id) && $id !== '' ? CatalogService::where('store_id', $store->id)->findOrFail($id) : null;
    }

    public function index(Request $request, Store $store): Response
    {
        $role = CatalogAccess::authorize($request, $store);
        $tab = $request->query('tab') === 'categories' ? 'categories' : 'services';
        $service = $this->selectedService($request, $store);
        $list = new ListQuery($request, [], 10);
        if ($tab === 'services') {
            $page = $list->paginate($list->apply(CatalogService::where('store_id', $store->id)->withCount(['categories', 'products']), ['name'])->orderBy('name')->orderBy('id'));
        } else {
            $query = CatalogCategory::where('catalog_categories.store_id', $store->id)->withCount('products')->with('service:id,name');
            if ($service) {
                $query->where('service_id', $service->id);
            }
            $page = $list->paginate($list->apply($query, ['name'])->orderBy('name')->orderBy('id'));
        }

        return Inertia::render('Owner/Taxonomy', ['role' => $role, 'store' => $store->only(['id', 'name', 'status']), 'tab' => $tab,
            'selectedService' => $service ? [...$service->only(['id', 'name']), 'quick_order_service' => app(QuickOrderCatalog::class)->service($service->name)] : null, 'canManage' => CatalogAccess::has($request, $role, 'products.manage'),
            'gameOptions' => app(GameIdentity::class)->games(),
            'providerOptions' => app(QuickOrderCatalog::class)->providers(),
            'items' => $page->getCollection()->map(fn ($item) => [...$item->toArray(),
                'provider' => $store->branding['category_providers'][$item->id] ?? '',
                'quick_order_service' => $tab === 'categories' ? app(QuickOrderCatalog::class)->service($item->service->name) : null,
                'destination_type' => $store->branding['category_destinations'][$item->id]['type'] ?? 'auto',
                'destination_columns' => $store->branding['category_destinations'][$item->id]['columns'] ?? 'auto',
                'game_validation' => $store->branding['category_games'][$item->id]['game'] ?? 'auto',
                'destination_format' => $store->branding['category_games'][$item->id]['destination_format'] ?? 'auto',
                'image' => $tab === 'categories' ? ($store->branding['category_images'][$item->id] ?? null) : null]),
            'filters' => $list->filters, 'pagination' => ListQuery::meta($page)]);
    }

    public function options(Request $request, Store $store): JsonResponse
    {
        CatalogAccess::authorize($request, $store);
        $data = $request->validate(['type' => 'required|in:services,categories,pricing_categories', 'service_id' => 'nullable|uuid']);
        $list = new ListQuery($request, [], 5);
        if ($data['type'] === 'services') {
            $query = CatalogService::where('store_id', $store->id);
        } elseif ($data['type'] === 'pricing_categories') {
            $query = CatalogCategory::where('store_id', $store->id)
                ->selectRaw('MIN(id) as id, MIN(name) as name, name_key')->groupBy('name_key');
        } else {
            $service = CatalogService::where('store_id', $store->id)->findOrFail($data['service_id'] ?? '');
            $query = CatalogCategory::where('store_id', $store->id)->where('service_id', $service->id);
        }
        $options = $list->options($list->apply($query, ['name'])->orderBy('name')->orderBy('id'));
        $options['items'] = $options['items']->map(fn ($item) => [...$item->only(['id', 'name']), 'quick_order_service' => $data['type'] === 'services' ? app(QuickOrderCatalog::class)->service($item->name) : null]);

        return response()->json($options)->header('Cache-Control', 'private, no-store');
    }

    public function destinationProfile(Request $request, Store $store): JsonResponse
    {
        CatalogAccess::authorize($request, $store, 'products.manage');
        $data = $request->validate(['name' => 'required|string|max:100', 'service_id' => 'required|uuid', 'category_id' => 'nullable|uuid',
            'game_validation' => ['required', Rule::in(['auto', 'none', ...array_keys(app(GameIdentity::class)->games())])]]);
        $service = CatalogService::where('store_id', $store->id)->findOrFail($data['service_id']);
        $kind = null;
        if (! empty($data['category_id'])) {
            $category = CatalogCategory::where('store_id', $store->id)->findOrFail($data['category_id']);
            $kinds = $category->products()->distinct()->pluck('kind');
            $kind = $kinds->count() === 1 ? $kinds->first() : null;
        }
        $category = new CatalogCategory(['id' => 'destination-preview', 'store_id' => $store->id, 'name' => trim($data['name'])]);
        $category->setRelation('service', $service);
        $draft = clone $store;
        $branding = $draft->branding ?? [];
        $branding['category_games'][$category->id] = ['game' => $data['game_validation'], 'destination_format' => 'auto'];
        $draft->branding = $branding;

        return response()->json(['profile' => app(CategoryDestination::class)->profile($draft, $category, $kind)])->header('Cache-Control', 'private, no-store');
    }

    private function name(Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'regex:/^[^\r\n]+$/u']]);
        $data['name'] = trim($data['name']);
        if ($data['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Nama wajib diisi.']);
        }

        return [...$data, 'name_key' => mb_strtolower($data['name'])];
    }

    public function service(Request $request, Store $store, CatalogTaxonomy $taxonomy, ?CatalogService $service = null): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'products.manage');
        CatalogAccess::operational($store);
        abort_if($service && $service->store_id !== $store->id, 404);
        $data = $this->name($request);
        DB::transaction(function () use ($store, $service, $data, $taxonomy) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            CatalogAccess::operational($locked);
            if (CatalogService::where('store_id', $store->id)->where('name_key', $data['name_key'])->when($service, fn ($q) => $q->whereKeyNot($service->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Nama layanan sudah digunakan pada toko ini.']);
            }
            if ($service) {
                $service->refresh();
                $taxonomy->preserveRule($store->id, 'service', $service->name, $data['name']);
                CatalogProduct::where('store_id', $store->id)->whereIn('category_id', $service->categories()->select('id'))->update(['service' => $data['name']]);
                $service->update($data);
            } else {
                CatalogService::create([...$data, 'store_id' => $store->id]);
            }
        });

        return back()->with('success', 'Layanan tersimpan. Nama pada produk terkait ikut diperbarui.');
    }

    public function category(Request $request, Store $store, CatalogTaxonomy $taxonomy, ?CatalogCategory $category = null): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'products.manage');
        CatalogAccess::operational($store);
        abort_if($category && $category->store_id !== $store->id, 404);
        $data = $this->name($request);
        $request->validate(['service_id' => [$category ? 'sometimes' : 'required', 'required', 'uuid', Rule::exists('catalog_services', 'id')->where('store_id', $store->id)]]);
        $appearance = $request->validate(['image_file' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096|dimensions:max_width=6000,max_height=6000',
            'provider' => ['sometimes', 'nullable', Rule::in(array_keys(config('mobile_providers')))],
            'image_url' => ['nullable', 'url:https', 'max:2048', 'regex:#^https://[^\s\\\\]+$#'], 'remove_image' => 'sometimes|boolean',
            'game_validation' => ['sometimes', 'required', Rule::in(['auto', 'none', ...array_keys(app(GameIdentity::class)->games())])],
            'destination_type' => ['sometimes', 'required', Rule::in(CategoryDestination::TYPES)],
            'destination_columns' => ['sometimes', 'required', Rule::in(['auto', '1', '2'])],
            'destination_format' => ['sometimes', 'required', Rule::in(GameIdentity::FORMATS)]]);
        $newFile = null;
        $oldImage = null;
        try {
            DB::transaction(function () use ($request, $store, $category, $data, $taxonomy, $appearance, &$newFile, &$oldImage) {
                $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
                CatalogAccess::operational($locked);
                $category?->refresh();
                $serviceId = $request->input('service_id', $category?->service_id);
                $service = CatalogService::where('store_id', $store->id)->findOrFail($serviceId);
                $quickService = app(QuickOrderCatalog::class)->service($service->name);
                if (! empty($appearance['provider']) && ! $quickService) {
                    throw ValidationException::withMessages(['provider' => 'Provider hanya tersedia untuk layanan Pulsa, Paket Data, dan Voucher Data.']);
                }
                if (CatalogCategory::where('service_id', $serviceId)->where('name_key', $data['name_key'])->when($category, fn ($q) => $q->whereKeyNot($category->id))->exists()) {
                    throw ValidationException::withMessages(['name' => 'Nama kategori sudah digunakan dalam layanan ini.']);
                }
                if ($category) {
                    $keepOld = CatalogCategory::where('store_id', $store->id)->where('name_key', $category->name_key)->whereKeyNot($category->id)->exists();
                    $taxonomy->preserveRule($store->id, 'category', $category->name, $data['name'], $keepOld);
                    CatalogProduct::where('store_id', $store->id)->where('category_id', $category->id)->update(['category' => $data['name'], 'service' => $service->name]);
                    $category->update([...$data, 'service_id' => $serviceId]);
                    $category->unsetRelation('service');
                } else {
                    $category = CatalogCategory::create([...$data, 'store_id' => $store->id, 'service_id' => $serviceId]);
                    $branding = $locked->branding ?? [];
                    $branding['category_image_preferences'][$category->id] = 'manual';
                    $locked->update(['branding' => $branding]);
                }
                if ($request->hasFile('image_file') || ! empty($appearance['image_url']) || ! empty($appearance['remove_image'])) {
                    $branding = $locked->branding ?? [];
                    $oldImage = $branding['category_images'][$category->id] ?? null;
                    $image = null;
                    if ($request->hasFile('image_file')) {
                        $newFile = $request->file('image_file')->store('storefront/'.$store->id, 'local');
                        if (! is_string($newFile)) {
                            throw new \RuntimeException('Gambar kategori tidak dapat disimpan.');
                        }
                        $image = '/storefront-media/'.$store->id.'/'.basename($newFile);
                    } elseif (! empty($appearance['image_url'])) {
                        $image = $appearance['image_url'];
                    }
                    $branding['category_image_preferences'][$category->id] = $image ? 'custom' : 'none';
                    if ($image) {
                        $branding['category_images'][$category->id] = $image;
                    } else {
                        unset($branding['category_images'][$category->id]);
                    }
                    $locked->update(['branding' => $branding]);
                }
                if (isset($appearance['game_validation'])) {
                    $branding = $locked->branding ?? [];
                    $branding['category_games'][$category->id] = ['game' => $appearance['game_validation'], 'destination_format' => $appearance['destination_format'] ?? 'auto'];
                    $locked->update(['branding' => $branding]);
                }
                if (array_key_exists('provider', $appearance) || ! $quickService) {
                    $branding = $locked->branding ?? [];
                    if ($quickService && ! empty($appearance['provider'])) {
                        $branding['category_providers'][$category->id] = $appearance['provider'];
                    } else {
                        unset($branding['category_providers'][$category->id]);
                    }
                    $locked->update(['branding' => $branding]);
                }
                if (isset($appearance['destination_type'])) {
                    $branding = $locked->branding ?? [];
                    $branding['category_destinations'][$category->id] = ['type' => $appearance['destination_type'], 'columns' => $appearance['destination_columns'] ?? 'auto'];
                    $locked->update(['branding' => $branding]);
                    $game = app(GameIdentity::class)->profile($locked, $category);
                    if ($game && ($appearance['destination_columns'] ?? 'auto') !== 'auto' && (int) $appearance['destination_columns'] !== ($game['server_label'] ? 2 : 1)) {
                        throw ValidationException::withMessages(['destination_columns' => 'Jumlah kolom harus sesuai game pengecekan nickname yang dipilih. Gunakan Otomatis atau nonaktifkan pengecekan nickname untuk format manual.']);
                    }
                }
                app(StoreCatalog::class)->reprice($locked);
            });
        } catch (\Throwable $error) {
            if (is_string($newFile)) {
                Storage::disk('local')->delete($newFile);
            }
            throw $error;
        }
        $this->removeUnusedImage($store->fresh(), $oldImage);

        return back()->with('success', 'Kategori utama tersimpan. Produk terkait tetap terhubung.');
    }

    public function deleteService(Request $request, Store $store, CatalogService $service): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'products.manage');
        abort_unless($service->store_id === $store->id, 404);
        DB::transaction(function () use ($store, $service) {
            CatalogAccess::operational(Store::whereKey($store->id)->lockForUpdate()->firstOrFail());
            if ($service->categories()->exists()) {
                throw ValidationException::withMessages(['delete' => 'Layanan masih memiliki kategori utama. Hapus kategori yang kosong terlebih dahulu.']);
            }
            $service->delete();
        });

        return back()->with('success', 'Layanan kosong berhasil dihapus.');
    }

    public function deleteCategory(Request $request, Store $store, CatalogCategory $category): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'products.manage');
        abort_unless($category->store_id === $store->id, 404);
        $oldImage = null;
        DB::transaction(function () use ($store, $category, &$oldImage) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            CatalogAccess::operational($locked);
            if ($category->products()->exists()) {
                throw ValidationException::withMessages(['delete' => 'Kategori masih dipakai produk. Pindahkan atau hapus produknya terlebih dahulu.']);
            }
            $category->delete();
            $branding = $locked->branding ?? [];
            $oldImage = $branding['category_images'][$category->id] ?? null;
            unset($branding['category_images'][$category->id], $branding['category_image_preferences'][$category->id]);
            unset($branding['category_games'][$category->id], $branding['category_destinations'][$category->id], $branding['category_providers'][$category->id]);
            $locked->update(['branding' => $branding]);
        });
        $this->removeUnusedImage($store->fresh(), $oldImage);

        return back()->with('success', 'Kategori utama kosong berhasil dihapus.');
    }

    private function removeUnusedImage(Store $store, ?string $image): void
    {
        if (! $image || in_array($image, $store->branding['category_images'] ?? [], true)) {
            return;
        }
        $designs = app(StorefrontDesign::class);
        $files = $designs->assets(['logo' => $image], $store->id);
        Storage::disk('local')->delete(array_diff($files, $designs->assets($store->branding['design'] ?? [], $store->id)));
    }
}
