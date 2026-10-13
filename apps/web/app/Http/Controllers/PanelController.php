<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use App\Support\PlatformHost;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PanelController extends Controller
{
    private function storeData(Store $store): array
    {
        return [...$store->only(['id', 'name', 'slug', 'category', 'plan', 'status', 'template', 'expires_at']),
            'url' => app(PlatformHost::class)->storeUrl($store)];
    }

    public function admin(Request $request)
    {
        abort_unless($request->user()?->role === 'admin', 403);

        return Inertia::render('Panel/Dashboard', ['role' => 'admin', 'preview' => false,
            'viewer' => $request->user()->only(['name']),
            'stores' => Store::with('domain')->latest()->limit(50)->get()->map(fn ($s) => $this->storeData($s)),
            'counts' => ['stores' => Store::count(), 'owners' => User::where('role', 'owner')->count()],
        ]);
    }

    public function owner(Request $request)
    {
        abort_unless($request->user()?->role === 'owner', 403);

        return Inertia::render('Panel/Dashboard', ['role' => 'owner', 'preview' => false,
            'viewer' => $request->user()->only(['name']), 'counts' => [], 'ownerStatus' => $request->user()->verification_status,
            'stores' => Store::with('domain')->where('owner_id', $request->user()->id)->latest()->get()
                ->map(fn ($s) => $this->storeData($s))]);
    }

    public function store(Request $request, Store $store)
    {
        abort_unless($request->user()?->role === 'owner' && $store->owner_id === $request->user()->id, 403);

        return Inertia::render('Panel/Dashboard', ['role' => 'owner', 'preview' => false,
            'viewer' => $request->user()->only(['name']), 'counts' => [], 'ownerStatus' => $request->user()->verification_status, 'stores' => [$this->storeData($store)]]);
    }

    public function staff(Request $request, Store $store)
    {
        $staff = $request->user('staff');
        abort_unless($staff && $staff->active && $staff->store_id === $store->id, 403);
        abort_unless(in_array('dashboard.view', $staff->permissions ?? [], true), 403);

        return Inertia::render('Panel/Dashboard', ['role' => 'staff', 'preview' => false,
            'viewer' => ['name' => $staff->name], 'counts' => [],
            'permissions' => $staff->permissions ?? [], 'stores' => [$this->storeData($store)]]);
    }
}
