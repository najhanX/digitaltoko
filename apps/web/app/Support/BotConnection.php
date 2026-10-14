<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BotConnection
{
    public static function access(Request $request, Store $store, bool $write = false): string
    {
        $owner = $request->user('web');
        if ($owner?->role === 'owner' && $owner->id === $store->owner_id) {
            return 'owner';
        }
        $staff = $request->user('staff');
        $permissions = $staff?->permissions ?? [];
        abort_unless($staff && $staff->active && $staff->store_id === $store->id
            && (in_array('bot.manage', $permissions, true) || (! $write && in_array('bot.view', $permissions, true))), 403);

        return 'staff';
    }

    public static function state(Store $store, bool $secrets = true): array
    {
        $state = Cache::get('bot:'.$store->id.':state', ['status' => 'DISCONNECTED']);
        if ($store->bot_connection_id && ($state['connection_id'] ?? null) !== $store->bot_connection_id) {
            $state = ['status' => 'DISCONNECTED'];
        }
        if (! $store->canConnectBot()) {
            $state = ['status' => 'STOPPED'];
        }
        if (isset($state['expires_at']) && now()->gte($state['expires_at']) && ($state['status'] ?? '') === 'PAIRING') {
            $state = ['status' => 'EXPIRED'];
        }
        if (! $secrets) {
            unset($state['qr'], $state['qr_image'], $state['pairing_code']);
        }

        return $state;
    }
}
