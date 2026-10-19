<?php

namespace App\Services;

use App\Models\Store;
use App\Support\OwnerOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CustomerOtp
{
    public function pending(Request $request, Store $store): ?array
    {
        $purpose = 'customer-'.$store->id.'-login';
        $id = $request->session()->get('owner_challenge_'.$purpose);
        $challenge = is_string($id) ? Cache::get('owner-challenge:'.$id) : null;
        if (! $challenge || $challenge['purpose'] !== $purpose || $challenge['expires_at'] <= now()->timestamp || $challenge['attempts'] >= 5 || ($challenge['payload']['store_id'] ?? null) !== $store->id) {
            return null;
        }
        $phone = $challenge['payload']['phone'];

        return ['phone' => substr($phone, 0, 4).'••••'.substr($phone, -4), 'expires_at' => $challenge['expires_at'], 'intent' => $challenge['payload']['intent'] ?? 'login'];
    }

    public function issue(Request $request, Store $store, string $purpose, string $channel, string $destination, array $data): void
    {
        app(OwnerOtp::class)->issue($request, 'customer-'.$store->id.'-'.$purpose, $channel, $destination, ['store_id' => $store->id, ...$data]);
    }

    public function consume(Request $request, Store $store, string $purpose, string $otp): array
    {
        $data = app(OwnerOtp::class)->consume($request, 'customer-'.$store->id.'-'.$purpose, $otp);
        abort_unless($data['store_id'] === $store->id, 403);

        return $data;
    }
}
