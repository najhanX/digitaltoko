<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use App\Support\PlatformHost;
use App\Support\StaffPermissions;
use App\Support\StorefrontDesign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StoreManagementController extends Controller
{
    private function owner(Request $request): User
    {
        abort_unless($request->user()?->role === 'owner', 403);

        return $request->user();
    }

    private function owned(Request $request, Store $store): User
    {
        $owner = $this->owner($request);
        abort_unless($store->owner_id === $owner->id, 404);
        abort_unless($owner->verification_status === 'approved', 403);

        return $owner;
    }

    public function create(Request $request): Response
    {
        $owner = $this->owner($request);
        abort_unless($owner->verification_status === 'approved' && $owner->email_verified_at && $owner->phone_verified_at, 403);

        return Inertia::render('Owner/CreateStore', ['platformDomain' => config('platform.domain')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);
        $request->merge(['slug' => strtolower(trim((string) $request->input('slug')))]);
        $data = $request->validate(['name' => 'required|string|max:100',
            'slug' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(config('platform.reserved_slugs')), Rule::unique('stores', 'slug')],
            'category' => 'required|in:bot,web,full', 'plan' => 'required|in:orbit,space,galaxy', 'template' => 'required|in:aurora,nebula,atlas']);
        $store = DB::transaction(function () use ($owner, $data) {
            $locked = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->verification_status === 'approved' && $locked->email_verified_at && $locked->phone_verified_at, 403);

            return Store::create([...$data, 'owner_id' => $owner->id, 'status' => 'draft', 'expires_at' => null, 'is_trial' => false]);
        });

        return redirect('/stores/'.$store->id.'/subscription')->with('success', 'Toko dibuat. Pilih paket dan durasi untuk melanjutkan. Masa sewa belum dimulai.');
    }

    public function show(Request $request, Store $store): Response
    {
        $this->owned($request, $store);

        return Inertia::render('Owner/ManageStore', ['role' => 'owner', 'store' => [...$store->only(['id', 'name', 'slug', 'category', 'plan', 'template', 'status', 'expires_at', 'is_trial', 'branding']),
            'url' => app(PlatformHost::class)->storeUrl($store)], 'staff' => StoreStaff::where('store_id', $store->id)->orderBy('name')
                ->get()->map(fn ($staff) => $staff->only(['id', 'name', 'username', 'email', 'permissions', 'active'])),
            'permissionLabels' => StaffPermissions::labels(), ...app(StorefrontDesign::class)->editor($store)]);
    }

    private function authorizedStaff(Request $request, Store $store): void
    {
        $staff = $request->user('staff');
        abort_unless($staff && $staff->active && $staff->store_id === $store->id && in_array('web.manage', $staff->permissions ?? [], true), 403);
    }

    public function staffSettings(Request $request, Store $store): Response
    {
        $this->authorizedStaff($request, $store);

        return Inertia::render('Owner/ManageStore', ['role' => 'staff', 'store' => [...$store->only(['id', 'name', 'slug', 'category', 'plan', 'template', 'status', 'expires_at', 'is_trial', 'branding']),
            'url' => app(PlatformHost::class)->storeUrl($store)], 'staff' => [], 'permissionLabels' => [], ...app(StorefrontDesign::class)->editor($store)]);
    }

    public function updateStaffSettings(Request $request, Store $store): RedirectResponse
    {
        $this->authorizedStaff($request, $store);
        $this->saveAppearance($request, $store);

        return back()->with('success', 'Pengaturan toko tersimpan.');
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $this->owned($request, $store);
        $this->saveAppearance($request, $store);

        return back()->with('success', 'Pengaturan toko tersimpan.');
    }

    private function saveAppearance(Request $request, Store $store): void
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'template' => 'required|in:aurora,nebula,atlas',
            'appearance' => 'sometimes|array:gaming_banner_title,gaming_banner_subtitle,gaming_banner_image,gaming_flash_category,gaming_flash_ends_at',
            'appearance.gaming_banner_title' => 'nullable|string|max:100',
            'appearance.gaming_banner_subtitle' => 'nullable|string|max:200',
            'appearance.gaming_banner_image' => 'nullable|url:https|max:1000',
            'appearance.gaming_flash_category' => 'nullable|string|max:100',
            'appearance.gaming_flash_ends_at' => 'nullable|date_format:Y-m-d\TH:i:sP',
        ]);
        DB::transaction(function () use ($store, $data) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('appearance', $data)) {
                $locked->branding = [...($locked->branding ?? []), ...$data['appearance']];
            }
            $locked->fill(['name' => $data['name'], 'template' => $data['template']])->save();
        });
    }

    public function saveStaff(Request $request, Store $store, ?StoreStaff $staff = null): RedirectResponse
    {
        $this->owned($request, $store);
        abort_if($staff && $staff->store_id !== $store->id, 404);
        $request->merge(['email' => strtolower(trim((string) $request->input('email'))), 'username' => trim((string) $request->input('username'))]);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'username' => ['required', 'regex:/^[a-zA-Z0-9_.-]{3,40}$/', Rule::unique('store_staff')->where('store_id', $store->id)->ignore($staff?->id)],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('store_staff')->where('store_id', $store->id)->ignore($staff?->id)],
            'password' => [$staff ? 'nullable' : 'required', 'string', 'min:12', 'max:128'],
            'active' => 'required|boolean', 'permissions' => 'present|array',
            'permissions.*' => ['string', 'distinct', Rule::in(array_keys(StaffPermissions::labels()))],
        ]);
        if (in_array('subscription.renew', $data['permissions'], true)) {
            $data['permissions'] = array_values(array_unique([...$data['permissions'], 'subscription.view']));
        }
        if (in_array('bot.manage', $data['permissions'], true)) {
            $data['permissions'] = array_values(array_unique([...$data['permissions'], 'bot.view']));
        }
        DB::transaction(function () use ($store, $staff, $data) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            if (! $staff && StoreStaff::where('store_id', $store->id)->count() >= 5) {
                throw ValidationException::withMessages(['name' => 'Maksimal 5 akun staff per toko.']);
            }
            if (empty($data['password'])) {
                unset($data['password']);
            }
            if ($staff) {
                $staff->update($data);
            } else {
                StoreStaff::create([...$data, 'store_id' => $store->id]);
            }
        });

        return back()->with('success', 'Akun staff tersimpan hanya di toko ini.');
    }

    public function deleteStaff(Request $request, Store $store, StoreStaff $staff): RedirectResponse
    {
        $this->owned($request, $store);
        abort_unless($staff->store_id === $store->id, 404);
        $staff->delete();

        return back()->with('success', 'Akun staff dihapus dari toko ini.');
    }
}
