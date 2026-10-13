<?php

namespace App\Http\Controllers;

use App\Models\Store;
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
            'status' => 'required|in:CONNECTING,PAIRING,CONNECTED,DISCONNECTED,STOPPED',
            'qr' => 'nullable|string|max:6000',
        ]);
        // Never accept a store_id from the worker body as authorization.
        Cache::put('bot:'.$store->id.':state', [...$data, 'updated_at' => now()->toIso8601String()], now()->addSeconds(90));

        return response()->json(['status' => 'SUCCESS', 'message' => 'Status bot diterima.']);
    }
}
