<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\CustomerNotice;
use App\Models\CustomerOrder;
use App\Models\Store;
use App\Services\CustomerCommerce;
use App\Services\CustomerPayments;
use App\Services\CustomerWallet;
use App\Support\CatalogAccess;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CustomerPanelController extends Controller
{
    public function index(Request $request, Store $store): Response
    {
        $role = CatalogAccess::authorize($request, $store, 'orders.view');
        $list = new ListQuery($request, ['status' => ['awaiting_payment', 'paid', 'processing', 'pending', 'success', 'failed', 'expired', 'review'], 'channel' => ['web', 'bot']], 10);
        $page = $list->paginate($list->apply(CustomerOrder::where('store_id', $store->id), ['number', 'destination'], ['status' => 'status', 'channel' => 'channel'])->latest()->orderByDesc('id'));

        return Inertia::render('Owner/Orders', ['role' => $role, 'store' => $store->only(['id', 'name', 'status']), 'items' => $page->getCollection()->map(fn ($order) => $order->only(['id', 'number', 'snapshot', 'destination', 'channel', 'status', 'payment_status', 'amount', 'total', 'created_at', 'customer_id'])),
            'filters' => $list->filters, 'pagination' => ListQuery::meta($page)]);
    }

    public function show(Request $request, Store $store, CustomerOrder $order): Response
    {
        $role = CatalogAccess::authorize($request, $store, 'orders.view');
        abort_unless($order->store_id === $store->id, 404);
        $data = app(CustomerCommerce::class)->publicData($order);
        unset($data['url'],$data['qr_image'],$data['payment_url'],$data['result'],$data['phone']);
        if (CatalogAccess::has($request, $role, 'customers.contacts')) {
            $data['phone'] = $order->phone;
        }
        Inertia::encryptHistory();

        return Inertia::render('Owner/OrderDetail', ['role' => $role, 'store' => $store->only(['id', 'name', 'status']), 'order' => [...$data, 'customer_id' => $order->customer_id, 'resolution' => $order->resolution],
            'attempts' => $order->attempts()->orderBy('position')->get()->map(fn ($a) => $a->only(['id', 'position', 'source', 'cost', 'status', 'message', 'created_at', 'updated_at'])),
            'canReview' => $role === 'owner' && $order->status === 'review' && $order->payment_status === 'paid' && ! $order->attempts()->whereNotNull('last_checked_at')->exists(), 'canResolve' => CatalogAccess::has($request, $role, 'guests.resolve'), 'canResend' => CatalogAccess::has($request, $role, 'orders.resend'),
            'canReveal' => CatalogAccess::has($request, $role, 'stock.credentials')]);
    }

    public function action(Request $request, Store $store, CustomerOrder $order): RedirectResponse
    {
        $data = $request->validate(['action' => 'required|in:resolve,resend,refresh,review', 'method' => 'required_if:action,resolve|in:manual_refund,reordered', 'note' => 'required_if:action,resolve,review|nullable|string|min:8|max:500']);
        $permission = ['resolve' => 'guests.resolve', 'resend' => 'orders.resend', 'refresh' => 'orders.view', 'review' => 'orders.view'][$data['action']];
        $role = CatalogAccess::authorize($request, $store, $permission);
        abort_unless($order->store_id === $store->id, 404);
        if ($data['action'] === 'review') {
            abort_unless($role === 'owner', 403);
            DB::transaction(function () use ($request, $store, $order, $data) {
                Store::whereKey($store->id)->lockForUpdate()->first();
                $order = CustomerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $engine = app(CustomerCommerce::class);
                $engine->ensure($order->status === 'review' && $order->payment_status === 'paid' && ! $order->attempts()->whereNotNull('last_checked_at')->exists(), 'Transaksi yang sudah dikirim ke pemasok harus menunggu kepastian hasil pemasok.');
                $order->update(['snapshot' => [...$order->snapshot, 'review_decision' => ['actor' => $request->user('web')->id, 'note' => $data['note'], 'at' => now()->toIso8601String()]]]);
                if ($order->purpose === 'topup') {
                    app(CustomerWallet::class)->apply($order->customer, $order->credit_amount, 'order:'.$order->id.':topup', 'topup');
                    $order->update(['status' => 'success', 'completed_at' => now(), 'message' => 'Pembayaran topup telah diperiksa owner dan dikreditkan.']);
                    $engine->notice($order, 'success');
                } else {
                    $engine->fail($order, 'Pembayaran telah diperiksa owner. Pesanan tidak dikirim ke pemasok; dana diselesaikan sesuai kebijakan refund.');
                }
            });
        } elseif ($data['action'] === 'resolve') {
            DB::transaction(function () use ($request, $store, $order, $data, $role) {
                Store::whereKey($store->id)->lockForUpdate()->first();
                $order = CustomerOrder::whereKey($order->id)->lockForUpdate()->first();
                app(CustomerCommerce::class)->ensure(! $order->customer_id && $order->status === 'failed' && $order->payment_status === 'paid' && ! $order->resolution, 'Hanya pesanan guest gagal terbayar yang dapat ditindaklanjuti.');
                $order->update(['resolution' => ['method' => $data['method'], 'note' => $data['note'], 'actor' => $role.':'.($request->user($role === 'staff' ? 'staff' : 'web')->id), 'at' => now()->toIso8601String()]]);
            });
        } elseif ($data['action'] === 'resend') {
            app(CustomerCommerce::class)->ensure($order->status === 'success', 'Hasil hanya dikirim ulang untuk pesanan sukses.');
            $notice = CustomerNotice::where('event', 'order:'.$order->id.':success')->firstOrFail();
            $notice->update(['wa_sent_at' => null, 'email_sent_at' => null]);
        } else {
            try {
                app(CustomerPayments::class)->refresh($order);
            } catch (\Throwable) {
                return back()->withErrors(['payment' => 'Gateway belum dapat dihubungi.']);
            }
        }

        return back()->with('success', 'Tindakan pesanan tercatat. Pengiriman dan pemrosesan berjalan melalui antrean.');
    }

    public function reveal(Request $request, Store $store, CustomerOrder $order): JsonResponse
    {
        CatalogAccess::authorize($request, $store, 'stock.credentials');
        abort_unless($order->store_id === $store->id && $order->status === 'success', 404);

        return response()->json(['result' => $order->result])->header('Cache-Control', 'private, no-store');
    }

    public function customers(Request $request, Store $store): Response
    {
        $role = CatalogAccess::authorize($request, $store, 'customers.view');
        $contacts = CatalogAccess::has($request, $role, 'customers.contacts');
        $list = new ListQuery($request, [], 10);
        $page = $list->paginate($list->apply(Customer::where('store_id', $store->id), $contacts ? ['name', 'phone', 'email'] : ['name'])->orderBy('name')->orderBy('id'));

        return Inertia::render('Owner/Customers', ['role' => $role, 'store' => $store->only(['id', 'name', 'status']), 'items' => $page->getCollection()->map(fn ($c) => $c->only(['id', 'name', 'balance', 'created_at', ...($contacts ? ['phone', 'email'] : [])])),
            'filters' => $list->filters, 'pagination' => ListQuery::meta($page), 'canLedger' => CatalogAccess::has($request, $role, 'wallet.view'), 'canSettle' => CatalogAccess::has($request, $role, 'wallet.settlement'), 'stopped' => ! $store->acceptsOrders() || app(CustomerCommerce::class)->settings($store)->closing]);
    }

    public function settle(Request $request, Store $store, Customer $customer): RedirectResponse
    {
        $role = CatalogAccess::authorize($request, $store, 'wallet.settlement');
        abort_unless($customer->store_id === $store->id, 404);
        $data = $request->validate(['amount' => 'required|integer|min:1|max:100000000', 'note' => 'required|string|min:8|max:300', 'reference' => 'required|uuid']);
        DB::transaction(function () use ($request, $role, $store, $customer, $data) {
            $store = Store::whereKey($store->id)->lockForUpdate()->first();
            app(CustomerCommerce::class)->ensure(! $store->acceptsOrders() || app(CustomerCommerce::class)->settings($store)->closing, 'Pencairan hanya dicatat saat toko berhenti.');
            app(CustomerWallet::class)->apply($customer, -$data['amount'], 'settlement:'.$store->id.':'.$data['reference'], 'settlement', ['note' => $data['note'], 'actor' => $role.':'.$request->user($role === 'staff' ? 'staff' : 'web')->id]);
        });

        return back()->with('success', 'Penyelesaian saldo manual tercatat. Tidak ada transfer bank otomatis.');
    }

    public function ledger(Request $request, Store $store, Customer $customer): Response
    {
        $role = CatalogAccess::authorize($request, $store, 'wallet.view');
        abort_unless($customer->store_id === $store->id, 404);
        $list = new ListQuery($request, [], 10);
        $page = $list->paginate($list->apply(CustomerLedger::where('store_id', $store->id)->where('customer_id', $customer->id), ['reason'])->latest()->orderByDesc('id'));

        return Inertia::render('Owner/CustomerLedger', ['role' => $role, 'store' => $store->only(['id', 'name', 'status']), 'customer' => $customer->only(['id', 'name', 'balance']), 'items' => $page->items(), 'filters' => $list->filters, 'pagination' => ListQuery::meta($page)]);
    }
}
