<?php

namespace App\Http\Controllers;

use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\Store;
use App\Services\CatalogTaxonomy;
use App\Support\CatalogAccess;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'selectedService' => $service?->only(['id', 'name']), 'canManage' => CatalogAccess::has($request, $role, 'products.manage'),
            'items' => $page->items(), 'filters' => $list->filters, 'pagination' => ListQuery::meta($page)]);
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
        $page = $list->paginate($list->apply($query, ['name'])->orderBy('name')->orderBy('id'));

        return response()->json(['items' => $page->getCollection()->map(fn ($item) => $item->only(['id', 'name'])), 'pagination' => ListQuery::meta($page)])->header('Cache-Control', 'private, no-store');
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
        if (! $category) {
            $request->validate(['service_id' => ['required', 'uuid', Rule::exists('catalog_services', 'id')->where('store_id', $store->id)]]);
        }
        DB::transaction(function () use ($request, $store, $category, $data, $taxonomy) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            CatalogAccess::operational($locked);
            $serviceId = $category?->service_id ?? $request->string('service_id')->toString();
            CatalogService::where('store_id', $store->id)->findOrFail($serviceId);
            if (CatalogCategory::where('service_id', $serviceId)->where('name_key', $data['name_key'])->when($category, fn ($q) => $q->whereKeyNot($category->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Nama kategori sudah digunakan dalam layanan ini.']);
            }
            if ($category) {
                $category->refresh();
                $keepOld = CatalogCategory::where('store_id', $store->id)->where('name_key', $category->name_key)->whereKeyNot($category->id)->exists();
                $taxonomy->preserveRule($store->id, 'category', $category->name, $data['name'], $keepOld);
                CatalogProduct::where('store_id', $store->id)->where('category_id', $category->id)->update(['category' => $data['name']]);
                $category->update($data);
            } else {
                CatalogCategory::create([...$data, 'store_id' => $store->id, 'service_id' => $serviceId]);
            }
        });

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
        DB::transaction(function () use ($store, $category) {
            CatalogAccess::operational(Store::whereKey($store->id)->lockForUpdate()->firstOrFail());
            if ($category->products()->exists()) {
                throw ValidationException::withMessages(['delete' => 'Kategori masih dipakai produk. Pindahkan atau hapus produknya terlebih dahulu.']);
            }
            $category->delete();
        });

        return back()->with('success', 'Kategori utama kosong berhasil dihapus.');
    }
}
