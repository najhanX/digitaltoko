<?php

namespace App\Http\Controllers;

use App\Models\CatalogProduct;
use App\Models\CustomerLedger;
use App\Models\CustomerOrder;
use App\Services\CategoryDestination;
use App\Services\CustomerAccess;
use App\Services\CustomerCommerce;
use App\Services\CustomerFulfillment;
use App\Services\CustomerInquiryService;
use App\Services\CustomerPayments;
use App\Services\GameIdentity;
use App\Services\GuestCheckout;
use App\Services\StoreCatalog;
use App\Services\StoreEnvironment;
use App\Support\HanshopIdentity;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerOrderController extends Controller
{
    public function lookup(Request $request, CustomerAccess $access): \Symfony\Component\HttpFoundation\Response
    {
        $store = $access->store($request);
        abort_unless($store->supportsWeb(), 404);
        $raw = $request->query('number', '');
        $number = is_string($raw) ? strtoupper(trim($raw)) : $raw;
        $searched = $number !== '';
        $error = null;
        $receipt = null;
        if ($searched) {
            if (Validator::make(['number' => $number], ['number' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_-]{8,64}$/']])->fails()) {
                $error = 'Masukkan nomor pesanan yang valid.';
            } else {
                $order = CustomerOrder::where('store_id', $store->id)->where('number', $number)
                    ->first(['number', 'status', 'payment_status', 'snapshot', 'amount', 'total', 'created_at', 'updated_at']);
                if ($order) {
                    $receipt = [...$order->only(['number', 'status', 'payment_status', 'amount', 'total', 'created_at', 'updated_at']), 'product' => $order->snapshot['name'] ?? 'Produk digital'];
                } else {
                    $error = 'Pesanan tidak ditemukan di toko ini. Periksa kembali nomor pada bukti pesanan Anda.';
                }
            }
        }
        Inertia::encryptHistory();

        return Inertia::render('Storefront/OrderLookup', ['store' => $store->only(['name', 'slug', 'template', 'branding']),
            'receipt' => $receipt, 'query' => is_string($number) ? mb_substr($number, 0, 64) : '', 'searched' => $searched, 'lookupError' => $error])
            ->toResponse($request)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function lookupDetail(Request $request, CustomerAccess $access, CustomerCommerce $commerce): RedirectResponse
    {
        $store = $access->store($request);
        abort_unless($store->supportsWeb(), 404);
        $data = $request->validate(['number' => 'required|string|max:64', 'phone' => 'required|string|max:30']);
        $message = ['phone' => 'Nomor pesanan atau WhatsApp tidak sesuai. Gunakan nomor yang diisi saat checkout.'];
        try {
            $phone = HanshopIdentity::phone($data['phone']);
        } catch (ValidationException) {
            throw ValidationException::withMessages($message);
        }
        $order = CustomerOrder::where('store_id', $store->id)->where('number', strtoupper(trim($data['number'])))->where('phone', $phone)->first();
        if (! $order) {
            throw ValidationException::withMessages($message);
        }

        return redirect('/orders/'.$order->id.'?access='.$commerce->token($order), 303)
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function checkout(Request $request, CustomerAccess $access, StoreCatalog $catalog): Response
    {
        $store = $access->store($request);
        abort_unless($store->supportsWeb() && $store->acceptsOrders(), 404);
        $product = $catalog->query($store)->whereKey($request->query('product'))->firstOrFail();
        Inertia::encryptHistory();
        $setting = app(CustomerCommerce::class)->settings($store);

        return Inertia::render('Storefront/Checkout', ['store' => $store->only(['name', 'slug', 'template', 'branding']), 'product' => $catalog->present($product, $store),
            'guestPendingOrder' => app(GuestCheckout::class)->summary($request, $store),
            'initialDestination' => (($prefill = $request->session()->pull('quick_order')) && ($prefill['store_id'] ?? null) === $store->id && ($prefill['product_id'] ?? null) === $product->id) ? ($prefill['destination'] ?? '') : '',
            'gameProfile' => app(GameIdentity::class)->profile($store, $product->taxonomyCategory),
            'destinationProfile' => app(CategoryDestination::class)->profile($store, $product->taxonomyCategory, $product->kind),
            'customer' => $access->summary($request, $store), 'allowGuest' => $setting->allow_guest, 'gateway' => app(StoreEnvironment::class)->gateway($store, $setting), 'feeMarkup' => $setting->fee_payer === 'customer' ? $setting->fee_markup : '0']);
    }

    public function inquiry(Request $request, CustomerAccess $access): JsonResponse
    {
        $store = $access->store($request);
        abort_unless($store->supportsWeb(), 403);
        $data = $request->validate(['product_id' => 'required|uuid', 'destination' => 'required|string|max:255', 'phone' => 'required|string|max:30', 'idempotency_key' => 'required|string|min:8|max:128']);
        $phone = $access->customer($request, $store)?->phone ?? $data['phone'];
        $service = app(CustomerInquiryService::class);
        $product = CatalogProduct::where('store_id', $store->id)->findOrFail($data['product_id']);
        $destination = app(CategoryDestination::class)->destination($store, $product, $data, $phone);
        $inquiry = $service->quote($store, $data['product_id'], $destination, $phone, $data['idempotency_key']);

        return response()->json(['data' => $service->present($inquiry)])->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, CustomerAccess $access, CustomerCommerce $commerce): RedirectResponse
    {
        $store = $access->store($request);
        $data = $request->validate(['idempotency_key' => 'required|string|max:128', 'purpose' => 'required|in:product,topup', 'product_id' => 'nullable|uuid',
            'phone' => 'required|string|max:30', 'email' => 'nullable|email:rfc|max:255', 'destination' => 'nullable|string|max:255', 'destination_id' => 'nullable|string|max:100', 'destination_server' => 'nullable|string|max:64', 'payment' => 'required|in:gateway,wallet', 'amount' => 'nullable|integer|min:5000|max:100000000', 'expected_price' => 'nullable|integer|min:1|max:100000000', 'inquiry_id' => 'nullable|uuid', 'game_verification' => 'nullable|string|max:4096']);
        abort_unless($data['purpose'] === 'topup' || $store->supportsWeb(), 403);
        $customer = $access->customer($request, $store);
        if (! $customer) {
            $guest = app(GuestCheckout::class);
            $data['guest_checkout_hash'] = $guest->key($request);
            if ($pending = $guest->pending($store, $data['guest_checkout_hash'])) {
                return redirect('/orders/'.$pending->id.'?access='.$commerce->token($pending))->with('error', 'Selesaikan atau batalkan pesanan ini sebelum membuat pesanan baru.');
            }
        }
        $order = $commerce->begin($store, $data, $customer);
        try {
            app(CustomerPayments::class)->start($order);
        } catch (\Throwable) {
            $order->update(['message' => 'Pembayaran belum dapat disiapkan. Coba tombol Periksa / siapkan pembayaran.']);
        }

        return redirect('/orders/'.$order->id.'?access='.$commerce->token($order));
    }

    public function show(Request $request, CustomerOrder $order, CustomerAccess $access, CustomerCommerce $commerce): \Symfony\Component\HttpFoundation\Response
    {
        $access->order($request, $order);
        Inertia::encryptHistory();
        try {
            app(CustomerPayments::class)->repairQr($order);
        } catch (\Throwable) {
            // Invoice remains available so the customer can retry preparing its QR.
        }

        return Inertia::render('Storefront/Order', ['store' => $order->store->only(['name', 'slug', 'template', 'branding']), 'order' => $commerce->publicData($order), 'canCancel' => $order->payment_status === 'unpaid' && $order->status === 'awaiting_payment' && (! $order->guest_checkout_hash || hash_equals($order->guest_checkout_hash, app(GuestCheckout::class)->key($request))), 'access' => $commerce->token($order)])->toResponse($request)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function refresh(Request $request, CustomerOrder $order, CustomerAccess $access): RedirectResponse
    {
        $access->order($request, $order);
        try {
            app(CustomerPayments::class)->start($order);
            app(CustomerPayments::class)->refresh($order->fresh());
        } catch (\Throwable) {
            return $this->receipt($order)->withErrors(['payment' => 'Pembayaran belum dapat diperiksa. Silakan coba kembali.']);
        }

        return $this->receipt($order)->with('success', 'Status pembayaran diperiksa. Pesanan terbayar diproses otomatis.');
    }

    public function simulate(Request $request, CustomerOrder $order, CustomerAccess $access, CustomerCommerce $commerce): RedirectResponse
    {
        $access->order($request, $order);
        try {
            app(CustomerPayments::class)->simulate($order);
        } catch (ValidationException $error) {
            return $this->receipt($order)->withErrors($error->errors());
        }
        app(CustomerFulfillment::class)->run($order->fresh());

        return $this->receipt($order)->with('success', 'Pembayaran uji diproses tanpa uang nyata.');
    }

    public function cancel(Request $request, CustomerOrder $order, CustomerAccess $access, CustomerPayments $payments): RedirectResponse
    {
        $access->order($request, $order);
        if ($order->guest_checkout_hash) {
            abort_unless(hash_equals($order->guest_checkout_hash, app(GuestCheckout::class)->key($request)), 403);
        }
        try {
            $payments->cancel($order);
        } catch (ValidationException $error) {
            return $this->receipt($order)->withErrors($error->errors());
        } catch (\Throwable) {
            return $this->receipt($order)->withErrors(['payment' => 'Pembatalan belum dapat dikonfirmasi. Periksa status pesanan, lalu coba kembali.']);
        }

        return $this->receipt($order)->with('success', 'Pesanan dibatalkan. Anda dapat membuat pesanan baru.');
    }

    private function receipt(CustomerOrder $order): RedirectResponse
    {
        return redirect('/orders/'.$order->id.'?access='.app(CustomerCommerce::class)->token($order), 303)
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function account(Request $request, CustomerAccess $access): Response|RedirectResponse
    {
        $store = $access->store($request);
        $customer = $access->customer($request, $store);
        if (! $customer) {
            return redirect('/account/login');
        }
        Inertia::encryptHistory();
        $tab = in_array($request->query('tab'), ['wallet', 'profile'], true) ? $request->query('tab') : 'orders';
        $list = new ListQuery($request, [], 10);
        $query = $tab === 'wallet' ? CustomerLedger::where('customer_id', $customer->id)->where('store_id', $store->id) : CustomerOrder::where('customer_id', $customer->id)->where('store_id', $store->id);
        if ($tab === 'profile') {
            $query->whereRaw('1 = 0');
        }
        $page = $list->paginate($list->apply($query->where('is_sandbox', $store->sandbox), $tab !== 'wallet' ? ['number', 'destination'] : ['reason', 'event'])->latest()->orderByDesc('id'));

        return Inertia::render('Storefront/Account', ['store' => $store->only(['name', 'slug', 'template', 'branding']), 'customer' => $access->summary($request, $store),
            'items' => $page->getCollection()->map(fn ($item) => $tab === 'orders' ? $item->only(['id', 'number', 'status', 'payment_status', 'amount', 'snapshot', 'created_at']) : $item->only(['id', 'reason', 'amount', 'balance_after', 'created_at'])),
            'tab' => $tab, 'filters' => $list->filters, 'pagination' => ListQuery::meta($page), 'accepting' => $store->acceptsOrders() && ! app(CustomerCommerce::class)->settings($store)->closing]);
    }
}
