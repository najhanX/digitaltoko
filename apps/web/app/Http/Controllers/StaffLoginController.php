<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\StoreStaff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class StaffLoginController extends Controller
{
    public function create(Store $store)
    {
        return Inertia::render('Auth/Login', ['mode' => 'staff', 'store' => $store->only(['id', 'name']), 'ownerOtpReady' => false]);
    }

    public function store(Request $request, Store $store)
    {
        $input = $request->validate(['identifier' => 'required|string|max:255', 'password' => 'required|string|max:255']);
        $field = str_contains($input['identifier'], '@') ? 'email' : 'username';
        $staff = StoreStaff::where('store_id', $store->id)->where('active', true)->where($field, $input['identifier'])->first();
        if (! $staff || ! Hash::check($input['password'], $staff->password)) {
            return back()->withErrors(['identifier' => 'Akun atau password tidak sesuai.']);
        }
        Auth::guard('web')->logout();
        Auth::guard('staff')->login($staff);
        $request->session()->regenerate();

        $permissions = $staff->permissions ?? [];
        $target = '/staff/'.$store->id.'/access';
        if (in_array('dashboard.view', $permissions, true)) {
            $target = '/staff/'.$store->id.'/dashboard';
        } elseif (in_array('subscription.view', $permissions, true)) {
            $target = '/stores/'.$store->id.'/subscription';
        } elseif (in_array('web.manage', $permissions, true)) {
            $target = '/staff/'.$store->id.'/settings';
        } elseif (in_array('notifications.view', $permissions, true) || in_array('notifications.manage', $permissions, true)) {
            $target = '/stores/'.$store->id.'/notifications';
        } elseif (in_array('bot.view', $permissions, true) || in_array('bot.manage', $permissions, true)) {
            $target = '/stores/'.$store->id.'/bot';
        } elseif (count(array_intersect(['products.view', 'products.manage', 'pricing.manage'], $permissions)) > 0) {
            $target = '/stores/'.$store->id.'/products';
        } elseif (count(array_intersect(['stock.view', 'stock.manage', 'stock.credentials'], $permissions)) > 0) {
            $target = '/stores/'.$store->id.'/stock';
        }

        if ($target === '/staff/'.$store->id.'/access') {
            if (count(array_intersect(['orders.view', 'orders.manage', 'orders.resend', 'guests.resolve', 'payments.confirm'], $permissions))) {
                $target = '/stores/'.$store->id.'/orders';
            } elseif (count(array_intersect(['customers.view', 'customers.contacts', 'wallet.view', 'wallet.settlement'], $permissions))) {
                $target = '/stores/'.$store->id.'/customers';
            } elseif (count(array_intersect(['payments.view', 'payments.policy', 'checkout.manage'], $permissions))) {
                $target = '/stores/'.$store->id.'/payments';
            }
        }

        return redirect($target);
    }

    public function destroy(Request $request)
    {
        $staff = $request->user('staff');
        $target = $staff ? '/staff/'.$staff->store_id.'/login' : ($request->user('web')?->role === 'admin' ? '/admin/login' : '/login');
        Auth::guard('staff')->logout();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($target);
    }
}
