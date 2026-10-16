<?php

namespace App\Http\Controllers;

use App\Models\ModulePurchase;
use App\Models\Store;
use App\Services\CommerceModules;
use App\Services\CustomerCommerce;
use App\Services\CustomQris;
use App\Services\ProductPricing;
use App\Support\CatalogAccess;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CommerceSettingsController extends Controller
{
    private function owner(Request $request, Store $store): void
    {
        abort_unless($request->user('web')?->role === 'owner' && $request->user('web')->id === $store->owner_id && $request->user('web')->verification_status === 'approved', 403);
    }

    public function show(Request $request, Store $store): Response
    {
        $role = CatalogAccess::authorize($request, $store, 'payments.view');
        $setting = app(CustomerCommerce::class)->settings($store);
        $list = new ListQuery($request, ['status' => ['pending', 'paid', 'expired', 'review']], 5);
        $page = $list->paginate($list->apply(ModulePurchase::where('store_id', $store->id), ['id'], ['status' => 'status'])->latest()->orderByDesc('id'));

        return Inertia::render('Owner/Payments', ['role' => $role, 'store' => $store->only(['id', 'name', 'status']), 'settings' => [...$setting->toArray(), 'application_package' => $setting->credentials['application_package'] ?? 'id.dana'],
            'configured' => ['midtrans' => filled($setting->credentials['server_key'] ?? null), 'custom' => filled($setting->credentials['qris_payload'] ?? null)],
            'canConnect' => $role === 'owner', 'canPolicy' => CatalogAccess::has($request, $role, 'payments.policy'), 'canCheckout' => CatalogAccess::has($request, $role, 'checkout.manage'),
            'modulePrice' => app(CommerceModules::class)->price(), 'invoices' => $page->items(), 'filters' => $list->filters, 'pagination' => ListQuery::meta($page),
            'callbackUrl' => url('/api/commerce/midtrans/'.$store->id), 'forwardUrl' => url('/api/commerce/forward/'.$store->id)]);
    }

    public function save(Request $request, Store $store): RedirectResponse
    {
        $data = $request->validate(['action' => 'required|in:connection,policy,checkout']);
        $action = $data['action'];
        if ($action === 'connection') {
            $this->owner($request, $store);
        } else {
            CatalogAccess::authorize($request, $store, $action === 'policy' ? 'payments.policy' : 'checkout.manage');
        }
        $setting = app(CustomerCommerce::class)->settings($store);
        if ($action === 'connection') {
            $data = $request->validate(['gateway' => 'required|in:midtrans,custom', 'sandbox' => 'required|boolean', 'server_key' => 'nullable|string|max:250', 'qris_payload' => 'nullable|string|max:4096', 'application_package' => 'required_if:gateway,custom|nullable|in:id.dana,com.shopeepay.id,com.shopee.id']);
            $credentials = $setting->credentials ?? [];
            if (filled($data['server_key'] ?? null)) {
                $credentials['server_key'] = $data['server_key'];
            }
            if (filled($data['qris_payload'] ?? null)) {
                app(CustomQris::class)->identity($data['qris_payload']);
                if (($credentials['qris_payload'] ?? null) !== $data['qris_payload']) {
                    $credentials['forward_token'] = Str::random(64);
                }
                $credentials['qris_payload'] = $data['qris_payload'];
            }
            if ($data['gateway'] === 'custom') {
                app(CustomerCommerce::class)->ensure($setting->custom_licensed_at !== null && filled($credentials['qris_payload'] ?? null), 'Beli modul QRIS custom dan lengkapi payload QRIS toko terlebih dahulu.');
                if (($credentials['application_package'] ?? 'id.dana') !== $data['application_package']) {
                    $credentials['forward_token'] = Str::random(64);
                }
                $credentials['application_package'] = $data['application_package'];
                $credentials['forward_token'] ??= Str::random(64);
            } else {
                app(CustomerCommerce::class)->ensure(filled($credentials['server_key'] ?? null), 'Server key Midtrans wajib diisi.');
            }
            $setting->update(['gateway' => $data['gateway'], 'sandbox' => $data['sandbox'], 'credentials' => $credentials]);
        } elseif ($action === 'policy') {
            $data = $request->validate(['fee_markup' => 'required|string|max:100', 'fee_payer' => 'required|in:store,customer', 'refund_unique' => 'required|boolean', 'topup_unique' => 'required|boolean']);
            $data['fee_markup'] = app(ProductPricing::class)->parse($data['fee_markup'])['text'];
            $setting->update($data);
        } elseif ($action === 'checkout') {
            $setting->update($request->validate(['allow_guest' => 'required|boolean']));
        }

        return back()->with('success', 'Pengaturan pembayaran tersimpan. Invoice lama tetap memakai pengaturan saat dibuat.');
    }

    public function forwarder(Request $request, Store $store): JsonResponse
    {
        $this->owner($request, $store);
        $setting = app(CustomerCommerce::class)->settings($store);
        abort_unless($setting->custom_licensed_at && filled($setting->credentials['forward_token'] ?? null), 404);

        return response()->json(['token' => $setting->credentials['forward_token']])->header('Cache-Control', 'private, no-store');
    }

    public function module(Request $request, Store $store, ?ModulePurchase $invoice = null): RedirectResponse
    {
        $this->owner($request, $store);
        if ($invoice) {
            abort_unless($invoice->store_id === $store->id, 404);
            app(CommerceModules::class)->sync($invoice);
        } else {
            app(CommerceModules::class)->buy($store);
        }

        return back()->with('success', 'Invoice modul diperiksa. Lisensi melekat pada toko setelah pembayaran sah.');
    }

    public function admin(Request $request): Response
    {
        abort_unless($request->user('web')?->role === 'admin', 403);

        return Inertia::render('Admin/Commerce', ['price' => app(CommerceModules::class)->price()]);
    }

    public function adminSave(Request $request): RedirectResponse
    {
        abort_unless($request->user('web')?->role === 'admin', 403);
        $data = $request->validate(['price' => 'required|integer|min:0|max:100000000']);
        app(CustomerCommerce::class)->ensure($data['price'] === 0 || $data['price'] >= 1000, 'Harga modul minimal Rp1.000, atau 0 untuk menghentikan penjualan.');
        DB::table('commerce_options')->updateOrInsert(['id' => 'custom_qris_price'], ['value' => $data['price']]);

        return back()->with('success', 'Harga modul tersimpan. Harga 0 menonaktifkan penjualan modul baru.');
    }
}
