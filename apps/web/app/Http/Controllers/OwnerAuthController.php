<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\HanshopIdentity;
use App\Support\OwnerOtp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OwnerAuthController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->user()) {
            return redirect($request->user()->role === 'admin' ? '/admin' : '/owner');
        }

        return Inertia::render('Auth/Login', ['mode' => 'owner', 'store' => null,
            'ownerOtpReady' => app(HanshopIdentity::class)->ready(), 'challengePending' => (bool) $request->session()->get('owner_challenge_login')]);
    }

    public function requestOtp(Request $request, HanshopIdentity $hanshop, OwnerOtp $otp): RedirectResponse
    {
        abort_if($request->user(), 403);
        $data = $request->validate(['intent' => 'required|in:login,register', 'identifier' => 'required|string|max:255',
            'channel' => 'required|in:wa,email', 'name' => 'required_if:intent,register|nullable|string|max:100']);
        $identity = $hanshop->call('identity', ['identifier' => trim($data['identifier'])]);
        $user = $identity['user'] ?? null;
        if (! $user && $data['intent'] === 'login') {
            throw ValidationException::withMessages(['identifier' => 'Akun tidak ditemukan. Daftar menggunakan nomor WhatsApp Anda.']);
        }
        if ($user) {
            $destination = $data['channel'] === 'wa' ? ($user['phone'] ?? '') : ($user['email'] ?? '');
            if (! $destination || ($data['channel'] === 'email' && empty($user['email_verified_at']))) {
                throw ValidationException::withMessages(['channel' => 'Email belum terverifikasi. Gunakan OTP WhatsApp.']);
            }
            $payload = ['identity' => $user, 'intent' => 'login', 'channel' => $data['channel']];
        } else {
            if ($data['channel'] !== 'wa') {
                throw ValidationException::withMessages(['channel' => 'Pendaftaran pertama wajib melalui WhatsApp.']);
            }
            $destination = HanshopIdentity::phone($data['identifier']);
            $payload = ['intent' => 'register', 'phone' => $destination, 'name' => $data['name'], 'channel' => 'wa'];
        }
        $otp->issue($request, 'login', $data['channel'], $destination, $payload);

        return back()->with('success', 'OTP dikirim. Berlaku selama 5 menit.');
    }

    public function verify(Request $request, OwnerOtp $otp, HanshopIdentity $hanshop): RedirectResponse
    {
        abort_if($request->user(), 403);
        $data = $request->validate(['otp' => 'required|digits:6']);
        $payload = $otp->consume($request, 'login', $data['otp']);
        $identity = $payload['intent'] === 'register'
            ? $hanshop->call('register', ['phone' => $payload['phone'], 'name' => $payload['name']])['user']
            : $hanshop->call('identity', ['identifier' => $payload['identity']['id']])['user'];
        if (! $identity || ($payload['intent'] === 'register' && $identity['phone'] !== $payload['phone']) || ($payload['intent'] === 'login' && ($identity['id'] !== $payload['identity']['id']
            || ($payload['channel'] === 'wa' ? $identity['phone'] !== $payload['identity']['phone'] : $identity['email'] !== $payload['identity']['email'])))) {
            throw ValidationException::withMessages(['otp' => 'Identitas berubah. Minta OTP baru.']);
        }
        if ($payload['channel'] === 'email' && empty($identity['email_verified_at'])) {
            throw ValidationException::withMessages(['otp' => 'Email belum terverifikasi. Minta OTP WhatsApp.']);
        }
        $user = Cache::lock('owner-identity-sync:'.hash('sha256', $identity['id']), 10)->block(3, function () use ($identity, $payload) {
            $user = User::where('hanshop_user_id', $identity['id'])->first();
            if ($user && $user->role !== 'owner') {
                abort(403);
            }
            if (! $user) {
                $user = new User;
                $user->password = Str::random(64);
                $user->role = 'owner';
                $user->hanshop_user_id = $identity['id'];
            }
            if ($user->phone !== $identity['phone']) {
                $user->phone_verified_at = null;
            }
            $user->phone = $identity['phone'];
            $user->name = $identity['name'];
            $user->email = $identity['email'] ?: strtolower($identity['id']).'@identity.invalid';
            $user->email_verified_at = $identity['email_verified_at'] ?? null;
            if ($payload['channel'] === 'wa') {
                $user->phone_verified_at = now();
            }
            $user->save();

            return $user;
        });
        Auth::guard('staff')->logout();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect('/owner');
    }
}
