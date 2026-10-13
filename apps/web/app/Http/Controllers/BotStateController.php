<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BotStateController extends Controller
{
    public function __invoke(Request $request, Store $store)
    {
        $owner = $request->user('web');
        $staff = $request->user('staff');
        $isOwner = $owner?->role === 'owner' && $store->owner_id === $owner->id;
        $isStaff = $staff && $staff->active && $staff->store_id === $store->id
            && in_array('bot.manage', $staff->permissions ?? [], true);
        abort_unless($isOwner || $isStaff, 403);

        return response()->json(['status' => 'SUCCESS', 'message' => 'Status koneksi toko.',
            'data' => Cache::get('bot:'.$store->id.':state', ['status' => 'DISCONNECTED'])])
            ->header('Cache-Control', 'no-store');
    }
}
