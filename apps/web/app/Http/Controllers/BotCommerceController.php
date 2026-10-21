<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerInquiry;
use App\Models\CustomerNotice;
use App\Models\CustomerOrder;
use App\Models\Store;
use App\Services\CustomerAccess;
use App\Services\CustomerCommerce;
use App\Services\CustomerInquiryService;
use App\Services\CustomerPayments;
use App\Services\StoreCatalog;
use App\Services\StoreRetention;
use App\Services\Vouchers;
use App\Support\HanshopIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BotCommerceController extends Controller
{
    public function handle(Request $request, CustomerAccess $access, CustomerCommerce $commerce): JsonResponse
    {
        $store = $access->worker($request);
        abort_unless(in_array($store->category, ['bot', 'full'], true) && $store->acceptsOrders(), 403);
        $data = $request->validate(['phone' => 'required|string|max:30', 'action' => 'required|in:quote,inquiry,buy,topup,balance,status', 'code' => 'required_if:action,quote|nullable|string|max:50',
            'product_id' => 'required_if:action,buy,inquiry|nullable|uuid', 'destination' => 'required_if:action,buy,inquiry|nullable|string|max:255', 'amount' => 'required_if:action,topup|nullable|integer|min:5000|max:100000000',
            'idempotency_key' => 'required_if:action,buy,topup,inquiry|nullable|string|max:128', 'payment' => 'nullable|in:gateway,wallet', 'number' => 'nullable|string|max:40', 'voucher_code' => 'nullable|string|max:40', 'expected_price' => 'nullable|integer|min:1', 'inquiry_id' => 'nullable|uuid']);
        $phone = HanshopIdentity::phone($data['phone']);
        $customer = DB::transaction(function () use ($store, $phone) {
            StoreRetention::guard(Store::whereKey($store->id)->lockForUpdate()->firstOrFail());

            $customer = Customer::firstOrCreate(['store_id' => $store->id, 'phone' => $phone], ['name' => 'Pelanggan '.substr($phone, -4), 'phone_verified_at' => now()]);
            abort_unless($customer->phone_verified_at, 403, 'Verifikasi nomor WhatsApp melalui akun web terlebih dahulu.');

            return $customer;
        });
        if ($data['action'] === 'balance') {
            $result = ['balance' => ($store->sandbox ? $customer->sandbox_balance : $customer->balance), 'phone' => $phone];
        } elseif ($data['action'] === 'status') {
            $query = CustomerOrder::where('store_id', $store->id)->where('is_sandbox', $store->sandbox)->where('phone', $phone);
            if (filled($data['number'] ?? null)) {
                $query->where('number', $data['number']);
            }
            $result = ['orders' => $query->latest()->limit(5)->get()->map(fn ($order) => $commerce->publicData($order))];
        } elseif ($data['action'] === 'inquiry') {
            $service = app(CustomerInquiryService::class);
            $result = ['inquiry' => $service->present($service->quote($store, $data['product_id'], $data['destination'], $phone, $data['idempotency_key']))];
        } elseif ($data['action'] === 'quote') {
            $product = app(StoreCatalog::class)->query($store)->where('code', strtoupper($data['code']))->firstOrFail();
            $result = ['product' => app(StoreCatalog::class)->present($product, $store), 'balance' => ($store->sandbox ? $customer->sandbox_balance : $customer->balance)];
            if (filled($data['voucher_code'] ?? null)) {
                $amount = $result['product']['price'];
                if ($product->kind === 'postpaid') {
                    $inquiry = CustomerInquiry::where('store_id', $store->id)->where('product_id', $product->id)->where('phone', $phone)->whereKey($data['inquiry_id'] ?? '')->where('expires_at', '>', now())->firstOrFail();
                    abort_unless((bool) ($inquiry->data['sandbox'] ?? false) === $store->sandbox, 422);
                    $amount = $inquiry->amount;
                }
                $result['voucher'] = app(Vouchers::class)->quote($store, $data['voucher_code'], $amount, $product);
            }
        } else {
            $order = $commerce->begin($store, [...$data, 'purpose' => $data['action'] === 'topup' ? 'topup' : 'product', 'phone' => $phone, 'destination' => $data['destination'] ?? $phone], $customer, 'bot');
            try {
                app(CustomerPayments::class)->start($order);
            } catch (\Throwable) {
                $order->update(['message' => 'QR pembayaran belum siap. Buka tautan pesanan untuk mencoba kembali.']);
            }
            $result = ['order' => $commerce->publicData($order->fresh())];
        }

        return response()->json(['status' => 'SUCCESS', 'data' => [...$result, 'sandbox' => $store->sandbox]])->header('Cache-Control', 'private, no-store');
    }

    public function notices(Request $request, CustomerAccess $access): JsonResponse
    {
        $store = $access->worker($request);
        abort_unless($store->canConnectBot(), 403);
        $notices = CustomerNotice::where('store_id', $store->id)->whereNull('wa_sent_at')->with('store')->oldest()->limit(10)->get();
        $items = $notices->map(function ($notice) {
            $order = $notice->order_id ? CustomerOrder::find($notice->order_id) : null;

            return ['id' => $notice->id, 'phone' => $notice->phone, 'message' => $notice->message.($order?->status === 'success' && str_ends_with($notice->event, ':success') && $order->result ? "\n\nHasil pesanan:\n".$order->result : '')];
        });

        return response()->json(['data' => $items])->header('Cache-Control', 'private, no-store');
    }

    public function delivered(Request $request, CustomerNotice $notice, CustomerAccess $access): JsonResponse
    {
        $store = $access->worker($request);
        abort_unless($notice->store_id === $store->id, 404);
        if (! $notice->wa_sent_at) {
            $notice->update(['wa_sent_at' => now()]);
        }

        return response()->json(['status' => 'SUCCESS']);
    }
}
