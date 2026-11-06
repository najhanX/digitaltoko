<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Http\Request;

class WorkspaceContext
{
    public function forRequest(Request $request): ?array
    {
        if (! $request->is('owner*', 'staff*', 'stores/*', 'admin*')) {
            return null;
        }
        $user = $request->user('web');
        $staff = $request->user('staff');
        $current = $request->route('store');
        $current = $current instanceof Store ? $current : null;
        $useStaff = ! $request->is('admin*') && $staff && $staff->active && ($request->is('staff/*')
            || ($current && $current->id === $staff->store_id && ! ($user?->role === 'owner' && $user->id === $current->owner_id)));
        if ($useStaff) {
            $stores = Store::whereKey($staff->store_id)->get(['id', 'name', 'category', 'plan', 'status', 'admin_suspended_at', 'admin_suspension_reason', 'sandbox']);

            return ['role' => 'staff', 'viewer' => ['name' => $staff->name], 'stores' => $stores,
                'currentStoreId' => $staff->store_id, 'permissions' => $staff->permissions ?? [], 'canCreate' => false];
        }
        if (! $user || ! in_array($user->role, ['owner', 'admin'], true)) {
            return null;
        }
        $stores = $user->role === 'owner' ? Store::where('owner_id', $user->id)->orderBy('name')->orderBy('id')->limit(20)->get(['id', 'name', 'category', 'plan', 'status', 'admin_suspended_at', 'admin_suspension_reason', 'sandbox']) : collect();
        $selectionKey = 'workspace.owner_store.'.$user->id;
        $selectedId = $current && $current->owner_id === $user->id ? $current->id : $request->session()->get($selectionKey);
        if ($selectedId && ! $stores->contains('id', $selectedId) && $user->role === 'owner') {
            $selected = Store::where('owner_id', $user->id)->whereKey($selectedId)->first(['id', 'name', 'category', 'plan', 'status', 'admin_suspended_at', 'admin_suspension_reason', 'sandbox']);
            if ($selected) {
                $stores->push($selected);
            }
        }
        if (! $stores->contains('id', $selectedId)) {
            $selectedId = $stores->count() === 1 ? $stores->first()->id : null;
        }
        if ($selectedId) {
            $request->session()->put($selectionKey, $selectedId);
        } else {
            $request->session()->forget($selectionKey);
        }

        return ['role' => $user->role, 'viewer' => ['name' => $user->name], 'stores' => $stores,
            'platformDomain' => config('platform.domain'),
            'currentStoreId' => $selectedId,
            'permissions' => [], 'verificationStatus' => $user->verification_status, 'identityRequired' => (bool) config('platform.owner_ktp_required'),
            'canCreate' => $user->role === 'owner' && $user->canUseOwnerFeatures() && ! $user->requiresEmailVerification() && (bool) $user->phone_verified_at];
    }
}
