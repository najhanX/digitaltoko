<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Store;
use App\Services\CustomerAccess;
use App\Services\CustomerCommerce;
use App\Services\CustomerOtp;
use App\Support\HanshopIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerAuthController extends Controller
{
    public function show(Request $request, CustomerAccess $access): Response
    {
        $store = $access->store($request);

        return Inertia::render('Storefront/CustomerLogin', ['store' => $store->only(['name', 'slug', 'template', 'branding']),
            'challengePending' => (bool) $request->session()->get('owner_challenge_customer-'.$store->id.'-login')]);
    }

    public function otp(Request $request, CustomerAccess $access, CustomerOtp $otp): RedirectResponse
    {
        $store = $access->store($request);
        $data = $request->validate(['phone' => 'required|string|max:30', 'name' => 'nullable|string|max:100']);
        $phone = HanshopIdentity::phone($data['phone']);
        $otp->issue($request, $store, 'login', 'wa', $phone, ['phone' => $phone, 'name' => $data['name'] ?? 'Pelanggan '.substr($phone, -4)]);

        return back()->with('success', 'OTP WhatsApp dikirim. Berlaku lima menit.');
    }

    public function verify(Request $request, CustomerAccess $access, CustomerOtp $otp): RedirectResponse
    {
        $store = $access->store($request);
        $data = $request->validate(['otp' => 'required|digits:6']);
        $payload = $otp->consume($request, $store, 'login', $data['otp']);
        $customer = DB::transaction(function () use ($store, $payload) {
            Store::whereKey($store->id)->lockForUpdate()->first();
            $customer = Customer::firstOrCreate(['store_id' => $store->id, 'phone' => $payload['phone']], ['name' => $payload['name']]);
            $customer->update(['phone_verified_at' => now()]);

            return $customer;
        });
        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();
        Inertia::clearHistory();

        return redirect('/account');
    }

    public function password(Request $request, CustomerAccess $access): RedirectResponse
    {
        $store = $access->store($request);
        $data = $request->validate(['email' => 'required|email:rfc|max:255', 'password' => 'required|string|max:128']);
        $customer = Customer::where('store_id', $store->id)->where('email', strtolower(trim($data['email'])))->whereNotNull('email_verified_at')->first();
        app(CustomerCommerce::class)->ensure($customer && $customer->password && Hash::check($data['password'], $customer->password), 'Email atau password tidak sesuai.');
        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();
        Inertia::clearHistory();

        return redirect('/account');
    }

    public function emailOtp(Request $request, CustomerAccess $access, CustomerOtp $otp): RedirectResponse
    {
        $store = $access->store($request);
        $customer = $access->customer($request, $store, true);
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255', Rule::unique('customers')->where('store_id', $store->id)->ignore($customer->id)]]);
        $otp->issue($request, $store, 'email', 'email', $data['email'], ['customer_id' => $customer->id, 'email' => $data['email']]);

        return back()->with('success', 'OTP verifikasi email dikirim.');
    }

    public function emailVerify(Request $request, CustomerAccess $access, CustomerOtp $otp): RedirectResponse
    {
        $store = $access->store($request);
        $customer = $access->customer($request, $store, true);
        $data = $request->validate(['otp' => 'required|digits:6', 'password' => 'required|string|min:12|max:128|confirmed']);
        $payload = $otp->consume($request, $store, 'email', $data['otp']);
        abort_unless($payload['customer_id'] === $customer->id, 403);
        DB::transaction(function () use ($store, $customer, $payload, $data) {
            Store::whereKey($store->id)->lockForUpdate()->first();
            app(CustomerCommerce::class)->ensure(! Customer::where('store_id', $store->id)->where('email', $payload['email'])->whereKeyNot($customer->id)->exists(), 'Email sudah digunakan.');
            $customer->update(['email' => $payload['email'], 'email_verified_at' => now(), 'password' => $data['password']]);
        });

        return back()->with('success', 'Email terverifikasi. Login email dan password sudah dapat digunakan.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->regenerate();
        Inertia::clearHistory();

        return redirect('/account/login');
    }
}
