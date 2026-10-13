<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;

class BotAccessController extends Controller
{
    public function __invoke(Request $request)
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 32, 401);
        $store = Store::where('worker_token_hash', hash('sha256', $token))->first();
        abort_unless($store, 401);
        $supportsBot = in_array($store->category, ['bot', 'full'], true);

        return response()->json(['status' => 'SUCCESS', 'message' => 'Hak bot dibaca.', 'data' => [
            'store_name' => $store->name, 'allowed' => $supportsBot && $store->acceptsOrders(),
            'can_connect' => $supportsBot && ($store->status === 'draft' || $store->acceptsOrders()),
        ]]);
    }
}
