<?php

namespace App\Services;

use App\Mail\CustomerOtpMail;
use App\Models\Store;
use App\Support\BotConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerOtp
{
    private function purpose(Store $store, string $purpose): string
    {
        return 'customer-'.$store->id.'-'.$purpose;
    }

    public function whatsappReady(Store $store): bool
    {
        return $store->canConnectBot() && BotConnection::state($store, false)['status'] === 'CONNECTED' && app(BotManager::class)->configured();
    }

    public function pending(Request $request, Store $store): ?array
    {
        $purpose = $this->purpose($store, 'login');
        $id = $request->session()->get('customer_challenge_'.$purpose);
        $challenge = is_string($id) ? Cache::get('customer-challenge:'.$id) : null;
        if (! $challenge || $challenge['purpose'] !== $purpose || $challenge['expires_at'] <= now()->timestamp || $challenge['attempts'] >= 5 || ($challenge['payload']['store_id'] ?? null) !== $store->id) {
            return null;
        }
        $destination = $challenge['channel'] === 'email' ? preg_replace('/^(.).+(@.+)$/', '$1••••$2', $challenge['destination']) : substr($challenge['destination'], 0, 4).'••••'.substr($challenge['destination'], -4);

        return ['phone' => $destination, 'channel' => $challenge['channel'], 'expires_at' => $challenge['expires_at'], 'intent' => $challenge['payload']['intent'] ?? 'login'];
    }

    public function issue(Request $request, Store $store, string $purpose, string $channel, string $destination, array $data): void
    {
        if ($channel === 'wa' && ! $this->whatsappReady($store)) {
            throw ValidationException::withMessages(['phone' => 'WhatsApp toko belum terhubung. Gunakan verifikasi melalui email.']);
        }
        $rateKey = 'customer-otp-send:'.hash_hmac('sha256', $store->id.':'.$channel.':'.$destination, config('app.key'));
        if (RateLimiter::tooManyAttempts($rateKey, 1)) {
            throw ValidationException::withMessages(['otp' => 'Tunggu satu menit sebelum meminta OTP kembali.']);
        }
        RateLimiter::hit($rateKey, 60);
        $otp = (string) random_int(100000, 999999);
        if ($channel === 'wa') {
            app(BotManager::class)->command($store, 'message', ['phone' => $destination, 'message' => '*Kode OTP '.$store->name."*\n\n".$otp."\n\nBerlaku 5 menit. Jangan bagikan kode ini kepada siapa pun."]);
        } else {
            app(StoreMail::class)->send($store, $destination, new CustomerOtpMail($store->name, $otp));
        }
        $purpose = $this->purpose($store, $purpose);
        $old = $request->session()->get('customer_challenge_'.$purpose);
        if ($old) {
            Cache::forget('customer-challenge:'.$old);
        }
        $id = (string) Str::uuid();
        Cache::put('customer-challenge:'.$id, ['hash' => Hash::make($otp), 'attempts' => 0, 'payload' => ['store_id' => $store->id, ...$data], 'purpose' => $purpose, 'channel' => $channel, 'destination' => $destination, 'expires_at' => now()->addMinutes(5)->timestamp], now()->addMinutes(5));
        $request->session()->put('customer_challenge_'.$purpose, $id);
    }

    public function consume(Request $request, Store $store, string $purpose, string $otp): array
    {
        $purpose = $this->purpose($store, $purpose);
        $id = $request->session()->get('customer_challenge_'.$purpose);
        if (! is_string($id)) {
            throw ValidationException::withMessages(['otp' => 'Minta kode OTP terlebih dahulu.']);
        }

        return Cache::lock('customer-challenge-lock:'.$id, 10)->block(3, function () use ($id, $purpose, $otp, $request, $store) {
            $key = 'customer-challenge:'.$id;
            $challenge = Cache::get($key);
            if (! $challenge || $challenge['purpose'] !== $purpose || $challenge['payload']['store_id'] !== $store->id || $challenge['attempts'] >= 5 || $challenge['expires_at'] <= now()->timestamp) {
                throw ValidationException::withMessages(['otp' => 'OTP kedaluwarsa atau percobaan habis. Minta kode baru.']);
            }
            if (! Hash::check($otp, $challenge['hash'])) {
                $challenge['attempts']++;
                if ($challenge['attempts'] >= 5) {
                    Cache::forget($key);
                } else {
                    Cache::put($key, $challenge, max(1, $challenge['expires_at'] - now()->timestamp));
                }
                throw ValidationException::withMessages(['otp' => 'Kode OTP tidak sesuai.']);
            }
            Cache::forget($key);
            $request->session()->forget('customer_challenge_'.$purpose);

            return [...$challenge['payload'], 'verified_channel' => $challenge['channel']];
        });
    }
}
