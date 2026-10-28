<?php

namespace App\Http\Controllers;

use App\Models\BotQrisMessage;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerInquiry;
use App\Models\CustomerNotice;
use App\Models\CustomerOrder;
use App\Models\Store;
use App\Services\CategoryDestination;
use App\Services\CustomerAccess;
use App\Services\CustomerCommerce;
use App\Services\CustomerInquiryService;
use App\Services\CustomerNoticeText;
use App\Services\CustomerPayments;
use App\Services\GameIdentity;
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
            'destination_id' => 'nullable|string|max:100', 'destination_server' => 'nullable|string|max:64',
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
            $profile = app(CategoryDestination::class)->profile($store, $product->taxonomyCategory, $product->kind);
            if ($product->kind === 'premium') {
                $profile = [...$profile, 'type' => 'premium', 'columns' => 1];
            } elseif ($profile['type'] === 'premium') {
                $profile = [...$profile, 'type' => 'other', 'columns' => 1, 'label' => 'Tujuan / ID pelanggan'];
            }
            $game = $profile['type'] === 'game' ? app(GameIdentity::class)->profile($store, $product->taxonomyCategory) : null;
            $result['destination_profile'] = [...$profile, 'server_label' => $game['server_label'] ?? 'Zona / server', 'server_options' => $game['server_options'] ?? []];
            if ($profile['type'] === 'premium' || filled($data['destination_id'] ?? null)) {
                if ($game) {
                    $fields = app(GameIdentity::class)->fields($game, ['id' => $data['destination_id'] ?? '', 'server' => $data['destination_server'] ?? '']);
                    $result['destination'] = app(GameIdentity::class)->destination($game, $fields['id'], $fields['server']);
                } elseif ($profile['type'] === 'premium') {
                    $result['destination'] = $phone;
                } elseif ($profile['type'] === 'other' && app(CategoryDestination::class)->profile($store, $product->taxonomyCategory, $product->kind)['type'] === 'premium') {
                    $result['destination'] = trim($data['destination_id']);
                    validator(['destination' => $result['destination']], ['destination' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/']])->validate();
                } else {
                    $result['destination'] = app(CategoryDestination::class)->destination($store, $product, [...$data, 'destination' => $data['destination_id'] ?? ''], $phone);
                }
            }
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
            if ($data['action'] === 'buy') {
                $product = CatalogProduct::where('store_id', $store->id)->findOrFail($data['product_id']);
                if ($product->kind === 'premium') {
                    $data['destination'] = $phone;
                }
            }
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
        $items = $notices->map(function ($notice) use ($store) {
            $order = $notice->order_id ? CustomerOrder::find($notice->order_id) : null;

            $message = $notice->message;
            if ($order?->status === 'success' && preg_match('/^order:[a-f0-9-]{36}:success(?:$|:)/i', $notice->event) && $order->result) {
                $message = app(CustomerNoticeText::class)->withResult($message, $order->result);
            }

            return ['id' => $notice->id, 'phone' => $notice->phone, 'message' => $message, 'order_id' => $order?->id, 'revision' => $notice->id, 'order_status' => $order?->status, 'payment_status' => $order?->payment_status,
                'commerce_enabled' => in_array($store->category, ['bot', 'full'], true) && $store->acceptsOrders() && in_array($order?->status, ['success', 'failed', 'expired'], true) && (bool) preg_match('/^order:[a-f0-9-]{36}:(?:success|failed|expired)(?:$|:)/i', $notice->event),
                'superseded' => $order && CustomerNotice::where('order_id', $order->id)->latest()->orderByDesc('id')->value('id') !== $notice->id];
        });

        $revocations = BotQrisMessage::where('store_id', $store->id)->whereNull('revoked_at')
            ->readyToRevoke()->with('order:id,phone')->oldest()->limit(25)->get()
            ->map(fn ($message) => ['id' => $message->id, 'phone' => $message->order->phone, 'key' => $message->message_key]);

        return response()->json(['data' => $items, 'qris_revocations' => $revocations])->header('Cache-Control', 'private, no-store');
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
