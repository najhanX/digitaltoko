<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\PanelNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BotEventController extends Controller
{
    public function __invoke(Request $request)
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 32, 401);
        $store = Store::where('worker_token_hash', hash('sha256', $token))->first();
        abort_unless($store, 401);
        $data = $request->validate([
            'status' => 'required|in:CONNECTING,PAIRING,CONNECTED,DISCONNECTED,STOPPED,ERROR,EXPIRED,LOGGED_OUT',
            'qr' => 'nullable|string|max:6000',
            'qr_image' => ['nullable', 'string', 'max:60000', 'regex:/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/'],
            'pairing_code' => ['nullable', 'string', 'regex:/^[A-Z0-9]{8}$/'],
            'connection_id' => 'nullable|uuid', 'phone' => 'nullable|string|max:15',
            'expires_at' => 'nullable|date', 'error' => 'nullable|in:PHONE_MISMATCH,PAIRING_FAILED,CREDENTIAL_FAILED,CONNECTION_FAILED,MANAGER_UNAVAILABLE',
        ]);
        abort_if($store->bot_connection_id && ($data['connection_id'] ?? null) !== $store->bot_connection_id, 409);
        abort_unless($store->canConnectBot(), 403);
        if ($data['status'] === 'CONNECTED' && $store->bot_phone) {
            abort_unless(($data['phone'] ?? null) === $store->bot_phone, 422);
        }
        if ($data['status'] !== 'PAIRING') {
            unset($data['qr'], $data['qr_image'], $data['pairing_code'], $data['expires_at']);
        } elseif ($store->bot_connection_id) {
            abort_unless(isset($data['expires_at']) && now()->lt($data['expires_at']) && now()->addMinutes(3)->gte($data['expires_at']), 422);
            if ($store->bot_method === 'code') {
                unset($data['qr'], $data['qr_image']);
            } else {
                unset($data['pairing_code']);
            }
        }
        // Never accept a store_id from the worker body as authorization.
        Cache::put('bot:'.$store->id.':state', [...$data, 'updated_at' => now()->toIso8601String()], now()->addSeconds(90));
        if ($store->bot_connection_id && in_array($data['status'], ['CONNECTED', 'ERROR', 'LOGGED_OUT', 'EXPIRED'], true)) {
            app(PanelNotifications::class)->forStore($store,
                $data['status'] === 'CONNECTED' ? 'WhatsApp toko terhubung' : 'Koneksi WhatsApp perlu diperiksa',
                $data['status'] === 'CONNECTED' ? 'WhatsApp '.$store->name.' berhasil terhubung. Untuk toko baru, lanjutkan konfirmasi aktivasi.' : 'Koneksi WhatsApp '.$store->name.' belum tersedia. Buka menu koneksi untuk menghubungkan kembali.',
                $data['status'] === 'CONNECTED' ? 'success' : 'warning', 'bot:'.$store->bot_connection_id.':'.$data['status'], 'bot.view');
        }

        return response()->json(['status' => 'SUCCESS', 'message' => 'Status bot diterima.']);
    }
}
