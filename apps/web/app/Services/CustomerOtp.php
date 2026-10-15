<?php

namespace App\Services;

use App\Models\Store;
use App\Support\OwnerOtp;
use Illuminate\Http\Request;

class CustomerOtp
{
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
