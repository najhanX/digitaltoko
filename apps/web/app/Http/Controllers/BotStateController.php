<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Support\BotConnection;
use Illuminate\Http\Request;

class BotStateController extends Controller
{
    public function __invoke(Request $request, Store $store)
    {
        $role = BotConnection::access($request, $store);
        $canManage = $role === 'owner' || in_array('bot.manage', $request->user('staff')->permissions ?? [], true);

        return response()->json(['status' => 'SUCCESS', 'message' => 'Status koneksi toko.',
            'data' => BotConnection::state($store, $canManage)])
            ->header('Cache-Control', 'no-store');
    }
}
