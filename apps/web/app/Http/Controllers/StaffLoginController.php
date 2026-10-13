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

        return redirect('/staff/'.$store->id.'/dashboard');
    }

    public function destroy(Request $request)
    {
        Auth::guard('staff')->logout();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
