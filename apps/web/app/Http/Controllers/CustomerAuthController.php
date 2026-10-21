<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Store;
use App\Services\CustomerAccess;
use App\Services\CustomerCommerce;
use App\Services\CustomerOtp;
use App\Services\StoreMail;
use App\Services\StoreRetention;
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
            'challengeExpiresAt' => $challenge['expires_at'] ?? null, 'challengeChannel' => $challenge['channel'] ?? 'wa',
            'otpReady' => $otp->whatsappReady($store), 'emailReady' => app(StoreMail::class)->ready($store),
            'canRegister' => $store->acceptsOrders() && ! app(CustomerCommerce::class)->settings($store)->closing]);
    }

    public function otp(Request $request, CustomerAccess $access, CustomerOtp $otp): RedirectResponse
    {
        $store = $access->store($request);
        $registration = $request->input('intent') === 'register';
        $target = $registration ? '/account/register' : '/account/login';
        try {
            $data = $request->validate(['channel' => 'nullable|in:wa,email', 'phone' => 'required_if:intent,register|required_unless:channel,email|nullable|string|max:30', 'name' => 'required_if:intent,register|nullable|string|max:100', 'email' => 'required_if:intent,register|required_if:channel,email|nullable|email:rfc|max:255', 'password' => 'required_if:intent,register|nullable|string|min:12|max:128|confirmed', 'intent' => 'nullable|in:login,register']);
            $channel = $data['channel'] ?? 'wa';
            if ($registration) {
                app(CustomerCommerce::class)->ensure($store->acceptsOrders() && ! app(CustomerCommerce::class)->settings($store)->closing, 'Toko belum menerima pendaftaran pelanggan baru.');
            }
            $email = filled($data['email'] ?? null) ? strtolower(trim($data['email'])) : null;
            $phone = filled($data['phone'] ?? null) ? HanshopIdentity::phone($data['phone']) : null;
            $existing = Customer::where('store_id', $store->id)->where($channel === 'email' && ! $registration ? 'email' : 'phone', $channel === 'email' && ! $registration ? $email : $phone)->first();
            if ($channel === 'email' && $registration && $existing && ($existing->email !== $email || ! $existing->email_verified_at)) {
                throw ValidationException::withMessages(['phone' => 'Nomor sudah terdaftar. Masuk menggunakan akun yang sudah ada.']);
            }
            if ($channel === 'email' && ! $registration && $existing && ! $existing->email_verified_at) {
                throw ValidationException::withMessages(['email' => 'Email belum diverifikasi. Masuk melalui WhatsApp lalu verifikasi email di akun Anda.']);
            }
            if ($channel === 'wa' && $existing && ! $existing->phone_verified_at && $existing->email_verified_at) {
                throw ValidationException::withMessages(['phone' => 'Nomor belum diverifikasi. Masuk menggunakan email, lalu verifikasi nomor di akun Anda.']);
            }
            if ($registration) {
                validator(['email' => $email], ['email' => [Rule::unique('customers')->where('store_id', $store->id)->ignore($existing?->id)]])->validate();
            } elseif (! $existing) {
                throw ValidationException::withMessages(['phone' => 'Nomor belum terdaftar di toko ini. Pilih Daftar pelanggan untuk membuat akun.']);
            }
            $otp->issue($request, $store, 'login', $channel, $channel === 'email' ? $email : $phone, [...($registration ? ['password_hash' => Hash::make($data['password'])] : []), 'customer_id' => $existing?->id, 'phone' => $phone ?? $existing?->phone, 'email' => $email, 'name' => filled($data['name'] ?? null) ? trim($data['name']) : 'Pelanggan '.substr($phone ?? '', -4), 'intent' => $registration ? 'register' : 'login']);
        } catch (ValidationException $error) {
            return redirect($target, 303)->withErrors($error->errors());
        }

        return redirect($target, 303)->with('success', 'OTP '.($channel === 'email' ? 'email' : 'WhatsApp toko').' dikirim. Berlaku lima menit.');
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
                StoreRetention::guard(Store::whereKey($store->id)->lockForUpdate()->firstOrFail());
                $customer = Customer::where('store_id', $store->id)->where('phone', $payload['phone'])->first();
                if ($payload['verified_channel'] === 'email' && $customer && ($customer->id !== $payload['customer_id'] || $customer->email !== $payload['email'])) {
                    throw ValidationException::withMessages(['email' => 'Data akun berubah. Minta OTP baru.']);
                }
                if (($payload['intent'] ?? '') !== 'register' && (! $customer || $customer->id !== $payload['customer_id'])) {
                    throw ValidationException::withMessages(['otp' => 'Data akun berubah. Minta OTP baru.']);
                }
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
                $customer->update([...($payload['verified_channel'] === 'wa' ? ['phone_verified_at' => now()] : ['email_verified_at' => now()]), ...(! $customer->password && filled($payload['password_hash'] ?? null) ? ['password' => $payload['password_hash']] : []), ...($email && ! $customer->email ? ['email' => $email] : [])]);

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
            StoreRetention::guard(Store::whereKey($store->id)->lockForUpdate()->firstOrFail());
            app(CustomerCommerce::class)->ensure(! Customer::where('store_id', $store->id)->where('email', $payload['email'])->whereKeyNot($customer->id)->exists(), 'Email sudah digunakan.');
            $customer->update(['email' => $payload['email'], 'email_verified_at' => now(), ...(filled($data['password'] ?? null) ? ['password' => $data['password']] : [])]);
        });

        return redirect('/account', 303)->with('success', 'Email terverifikasi. Login email dan password sudah dapat digunakan.');
    }

    public function phoneOtp(Request $request, CustomerAccess $access, CustomerOtp $otp): RedirectResponse
    {
        $store = $access->store($request);
        $customer = $access->customer($request, $store, true);
        $otp->issue($request, $store, 'phone', 'wa', $customer->phone, ['customer_id' => $customer->id, 'phone' => $customer->phone]);

        return redirect('/account?tab=profile', 303)->with('success', 'OTP dikirim melalui WhatsApp toko.');
    }

    public function phoneVerify(Request $request, CustomerAccess $access, CustomerOtp $otp): RedirectResponse
    {
        $store = $access->store($request);
        $customer = $access->customer($request, $store, true);
        $data = $request->validate(['otp' => 'required|digits:6']);
        $payload = $otp->consume($request, $store, 'phone', $data['otp']);
        abort_unless($payload['customer_id'] === $customer->id && $payload['phone'] === $customer->phone, 403);
        $customer->update(['phone_verified_at' => now()]);

        return redirect('/account?tab=profile', 303)->with('success', 'Nomor WhatsApp terverifikasi.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->regenerate();
        Inertia::clearHistory();

        return redirect('/account/login');
    }
}
