<?php

namespace App\Http\Controllers;

use App\Models\CustomerOrder;
use App\Models\Store;
use App\Models\StorePaymentSetting;
use App\Services\CustomerCommerce;
use App\Services\CustomerPayments;
use App\Services\SharedQris;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommerceWebhookController extends Controller
{
    public function forwardProfile(Request $request, string $profile, CustomerPayments $payments): JsonResponse
    {
        $credentials = app(SharedQris::class)->credentials($profile);
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 32 && hash_equals($credentials['forward_token'], $token), 401);
        $owner = DB::table('qris_profiles')->where('id', $profile)->value('owner_id');
        $store = Store::where('owner_id', $owner)->whereIn('id', StorePaymentSetting::whereNotNull('custom_licensed_at')->select('store_id'))->first();
        $data = $request->validate(['event_id' => 'required|string|max:200', 'application_package' => 'required|string|max:100', 'app_name' => 'nullable|string|max:100', 'title' => 'nullable|string|max:500', 'text' => 'required|string|max:4000', 'occurred_at' => 'required|date']);
        if (preg_match('/^test-[a-f0-9-]{36}$/i', $data['event_id']) && ($data['title'] ?? '') === 'Uji koneksi' && $data['text'] === 'Uji koneksi HansDev Forwarder — bukan pembayaran') {
            return response()->json(['status' => 'SUCCESS', 'message' => 'Endpoint profil dan token valid. Uji koneksi ini tidak mencatat uang atau melunasi pesanan.']);
        }
        $payments->custom($store, $data, $credentials);

        return response()->json(['status' => 'SUCCESS', 'message' => 'Mutasi dicatat satu kali dan dicocokkan ke tujuan pembayarannya.']);
    }

    public function midtrans(Request $request, Store $store, CustomerPayments $payments): JsonResponse
    {
        $data = $request->validate(['order_id' => 'required|string|max:40', 'status_code' => 'required|string|max:10', 'gross_amount' => 'required|string|max:30', 'signature_key' => 'required|string|max:128']);
        $order = CustomerOrder::where('store_id', $store->id)->where('number', $data['order_id'])->firstOrFail();
        $payments->notification($order, $request->all());

        return response()->json(['status' => 'SUCCESS', 'message' => 'Notifikasi diverifikasi melalui status server.']);
    }

    public function forward(Request $request, Store $store, CustomerPayments $payments): JsonResponse
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 32, 401);
        $setting = app(CustomerCommerce::class)->settings($store);
        $credentials = $setting->credentials ?? [];
        $matched = is_string($credentials['forward_token'] ?? null) && hash_equals($credentials['forward_token'], $token) ? $credentials : null;
        if (! $matched) {
            foreach (CustomerOrder::where('store_id', $store->id)->where('gateway', 'custom')->where('created_at', '>=', now()->subDay())->select(['id', 'payment_credentials'])->lazyById(100) as $order) {
                $credentials = $order->payment_credentials;
                if (is_string($credentials['forward_token'] ?? null) && hash_equals($credentials['forward_token'], $token)) {
                    $matched = $credentials;
                    break;
                }
            }
        }
        abort_unless($matched, 401);
        $data = $request->validate(['event_id' => 'required|string|max:200', 'application_package' => 'required|string|max:100', 'app_name' => 'nullable|string|max:100', 'title' => 'nullable|string|max:500', 'text' => 'required|string|max:4000', 'occurred_at' => 'required|date']);
        $payments->custom($store, $data, $matched);

        return response()->json(['status' => 'SUCCESS', 'message' => 'Notifikasi pembayaran diperiksa.']);
    }
}
