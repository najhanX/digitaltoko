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
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerAuthController extends Controller
{
    public function show(Request $request, CustomerAccess $access, CustomerOtp $otp): Response|RedirectResponse
    {
        $store = $access->store($request);
        if ($access->customer($request, $store)) {
            return redirect('/account');
        }
        $challenge = $otp->pending($request, $store);

        return Inertia::render('Storefront/CustomerLogin', ['store' => $store->only(['name', 'slug', 'template', 'branding']),
            'initialMode' => $request->is('account/register') ? 'register' : 'login',
            'challengePending' => (bool) $challenge, 'challengePhone' => $challenge['phone'] ?? null,
            'challengeExpiresAt' => $challenge['expires_at'] ?? null, 'otpReady' => app(HanshopIdentity::class)->ready(),
            'canRegister' => $store->acceptsOrders() && ! app(CustomerCommerce::class)->settings($store)->closing]);
    }

    public function otp(Request $request, CustomerAccess $access, CustomerOtp $otp): RedirectResponse
    {
        $store = $access->store($request);
        $registration = $request->input('intent') === 'register';
        $target = $registration ? '/account/register' : '/account/login';
        try {
            $data = $request->validate(['phone' => 'required|string|max:30', 'name' => 'required_if:intent,register|nullable|string|max:100', 'email' => 'required_if:intent,register|nullable|email:rfc|max:255', 'password' => 'required_if:intent,register|nullable|string|min:12|max:128|confirmed', 'intent' => 'nullable|in:login,register']);
            if ($registration) {
                app(CustomerCommerce::class)->ensure($store->acceptsOrders() && ! app(CustomerCommerce::class)->settings($store)->closing, 'Toko belum menerima pendaftaran pelanggan baru.');
            }
            $phone = HanshopIdentity::phone($data['phone']);
            $existing = Customer::where('store_id', $store->id)->where('phone', $phone)->first();
            $email = $registration ? strtolower(trim($data['email'])) : null;
            if ($registration) {
                validator(['email' => $email], ['email' => [Rule::unique('customers')->where('store_id', $store->id)->ignore($existing?->id)]])->validate();
            } elseif (! $existing) {
                throw ValidationException::withMessages(['phone' => 'Nomor belum terdaftar di toko ini. Pilih Daftar pelanggan untuk membuat akun.']);
            }
            $otp->issue($request, $store, 'login', 'wa', $phone, [...($registration ? ['password_hash' => Hash::make($data['password'])] : []), 'phone' => $phone, 'email' => $email, 'name' => filled($data['name'] ?? null) ? trim($data['name']) : 'Pelanggan '.substr($phone, -4), 'intent' => $registration ? 'register' : 'login']);
        } catch (ValidationException $error) {
            return redirect($target, 303)->withErrors($error->errors());
        }

        return redirect($target, 303)->with('success', 'OTP WhatsApp dikirim. Berlaku lima menit.');
    }

    public function verify(Request $request, CustomerAccess $access, CustomerOtp $otp): RedirectResponse
    {
        $store = $access->store($request);
        try {
            $data = $request->validate(['otp' => 'required|digits:6', 'intent' => 'nullable|in:login,register']);
            $payload = $otp->consume($request, $store, 'login', $data['otp']);
        } catch (ValidationException $error) {
            return redirect($request->input('intent') === 'register' ? '/account/register' : '/account/login', 303)->withErrors($error->errors());
        }
        try {
            $customer = DB::transaction(function () use ($store, $payload) {
                Store::whereKey($store->id)->lockForUpdate()->first();
                $customer = Customer::where('store_id', $store->id)->where('phone', $payload['phone'])->first();
                $email = ($payload['intent'] ?? '') === 'register' ? ($payload['email'] ?? null) : null;
                if ($email && (! $customer || ! $customer->email)) {
                    if (Customer::where('store_id', $store->id)->where('email', $email)->when($customer, fn ($query) => $query->whereKeyNot($customer->id))->exists()) {
                        throw ValidationException::withMessages(['email' => 'Email sudah digunakan oleh pelanggan lain di toko ini.']);
                    }
                }
                if (! $customer) {
                    app(CustomerCommerce::class)->ensure(filled($email) && filled($payload['password_hash'] ?? null), 'Lengkapi email dan password melalui halaman Daftar pelanggan, lalu minta OTP baru.');
                    $fresh = $store->fresh();
                    app(CustomerCommerce::class)->ensure($fresh->acceptsOrders() && ! app(CustomerCommerce::class)->settings($fresh)->closing, 'Toko belum menerima pendaftaran pelanggan baru.');
                    $customer = Customer::create(['store_id' => $store->id, 'phone' => $payload['phone'], 'name' => $payload['name'], 'email' => $email, 'password' => $payload['password_hash']]);
                }
                $customer->update(['phone_verified_at' => now(), ...(! $customer->password && filled($payload['password_hash'] ?? null) ? ['password' => $payload['password_hash']] : []), ...($email && ! $customer->email ? ['email' => $email, 'email_verified_at' => null] : [])]);

                return $customer;
            });
        } catch (ValidationException $error) {
            return redirect(($payload['intent'] ?? '') === 'register' ? '/account/register' : '/account/login', 303)->withErrors($error->errors());
        }
        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();
        Inertia::clearHistory();

        return redirect('/account')->with('success', ($payload['intent'] ?? '') === 'register' ? 'Akun pelanggan siap digunakan. Selamat datang di '.$store->name.'.' : 'Berhasil masuk ke akun pelanggan.');
    }

    public function password(Request $request, CustomerAccess $access): RedirectResponse
    {
        $store = $access->store($request);
        try {
            $data = $request->validate(['email' => 'required|email:rfc|max:255', 'password' => 'required|string|max:128']);
            $customer = Customer::where('store_id', $store->id)->where('email', strtolower(trim($data['email'])))->whereNotNull('email_verified_at')->first();
            app(CustomerCommerce::class)->ensure($customer && $customer->password && Hash::check($data['password'], $customer->password), 'Email atau password tidak sesuai.');
        } catch (ValidationException $error) {
            return redirect('/account/login', 303)->withErrors($error->errors());
        }
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

        return redirect('/account', 303)->with('success', 'OTP verifikasi email dikirim.');
    }

    public function emailVerify(Request $request, CustomerAccess $access, CustomerOtp $otp): RedirectResponse
    {
        $store = $access->store($request);
        $customer = $access->customer($request, $store, true);
        $data = $request->validate(['otp' => 'required|digits:6', 'password' => [Rule::requiredIf(! $customer->password), 'nullable', 'string', 'min:12', 'max:128', 'confirmed']]);
        $payload = $otp->consume($request, $store, 'email', $data['otp']);
        abort_unless($payload['customer_id'] === $customer->id, 403);
        DB::transaction(function () use ($store, $customer, $payload, $data) {
            Store::whereKey($store->id)->lockForUpdate()->first();
            app(CustomerCommerce::class)->ensure(! Customer::where('store_id', $store->id)->where('email', $payload['email'])->whereKeyNot($customer->id)->exists(), 'Email sudah digunakan.');
            $customer->update(['email' => $payload['email'], 'email_verified_at' => now(), ...(filled($data['password'] ?? null) ? ['password' => $data['password']] : [])]);
        });

        return redirect('/account', 303)->with('success', 'Email terverifikasi. Login email dan password sudah dapat digunakan.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->regenerate();
        Inertia::clearHistory();

        return redirect('/account/login');
    }
}
