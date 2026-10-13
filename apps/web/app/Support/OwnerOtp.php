<?php

namespace App\Support;

use App\Mail\OwnerOtpMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OwnerOtp
{
    public function issue(Request $request, string $purpose, string $channel, string $destination, array $payload): void
    {
        $rateKey = 'owner-otp-send:'.hash_hmac('sha256', $channel.':'.$destination, config('app.key'));
        if (RateLimiter::tooManyAttempts($rateKey, 1)) {
            throw ValidationException::withMessages(['identifier' => 'Tunggu satu menit sebelum meminta OTP kembali.']);
        }
        RateLimiter::hit($rateKey, 60);
        $otp = (string) random_int(100000, 999999);
        if ($channel === 'wa') {
            app(HanshopIdentity::class)->call('otp', ['phone' => $destination, 'otp' => $otp]);
        } else {
            if (in_array(config('mail.default'), ['log', 'array'], true) && ! app()->environment('testing')) {
                throw ValidationException::withMessages(['email' => 'Pengiriman email belum dikonfigurasi. Gunakan WhatsApp untuk login.']);
            }
            try {
                Mail::to($destination)->send(new OwnerOtpMail($otp));
            } catch (\Throwable $exception) {
                throw ValidationException::withMessages(['email' => 'Email OTP belum berhasil dikirim. Silakan coba kembali.']);
            }
        }
        $old = $request->session()->get('owner_challenge_'.$purpose);
        if ($old) {
            Cache::forget('owner-challenge:'.$old);
        }
        $id = (string) Str::uuid();
        Cache::put('owner-challenge:'.$id, ['hash' => Hash::make($otp), 'attempts' => 0, 'payload' => $payload, 'purpose' => $purpose, 'expires_at' => now()->addMinutes(5)->timestamp], now()->addMinutes(5));
        $request->session()->put('owner_challenge_'.$purpose, $id);
    }

    public function consume(Request $request, string $purpose, string $otp): array
    {
        $id = $request->session()->get('owner_challenge_'.$purpose);
        if (! $id) {
            throw ValidationException::withMessages(['otp' => 'Minta kode OTP terlebih dahulu.']);
        }

        return Cache::lock('owner-challenge-lock:'.$id, 10)->block(3, function () use ($id, $purpose, $otp, $request) {
            $key = 'owner-challenge:'.$id;
            $challenge = Cache::get($key);
            if (! $challenge || $challenge['purpose'] !== $purpose || $challenge['attempts'] >= 5 || $challenge['expires_at'] <= now()->timestamp) {
                throw ValidationException::withMessages(['otp' => 'OTP kedaluwarsa atau percobaan habis. Minta kode baru.']);
            }
            if (! Hash::check($otp, $challenge['hash'])) {
                $challenge['attempts']++;
                if ($challenge['attempts'] >= 5) {
                    Cache::forget($key);
                } else {
                    $remaining = max(1, $challenge['expires_at'] - now()->timestamp);
                    Cache::put($key, $challenge, $remaining);
                }
                throw ValidationException::withMessages(['otp' => 'Kode OTP tidak sesuai.']);
            }
            Cache::forget($key);
            $request->session()->forget('owner_challenge_'.$purpose);

            return $challenge['payload'];
        });
    }
}
