<?php

namespace App\Services;

use App\Models\CustomerOrder;
use App\Models\Store;
use App\Support\HanshopIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SharedQris
{
    public function profiles(Store $store): array
    {
        return DB::table('qris_profiles')->where('owner_id', $store->owner_id)->orderBy('name')->get(['id', 'name'])->map(fn ($row) => (array) $row)->all();
    }

    public function credentials(string $id, ?int $owner = null): array
    {
        $profile = DB::table('qris_profiles')->where('id', $id)->when($owner !== null, fn ($query) => $query->where('owner_id', $owner))->first();
        abort_unless($profile, 404, 'Profil QRIS tidak ditemukan.');

        return [...json_decode(Crypt::decryptString($profile->credentials), true, 512, JSON_THROW_ON_ERROR), 'qris_profile_id' => $id];
    }

    public function connect(Store $store, array $credentials, ?string $profileId): array
    {
        if (filled($profileId)) {
            return [...$credentials, ...$this->credentials($profileId, (int) $store->owner_id)];
        }
        $merchant = hash('sha256', app(CustomQris::class)->identity($credentials['qris_payload']));

        return DB::transaction(function () use ($store, $merchant, $credentials) {
            DB::table('commerce_locks')->insertOrIgnore(['id' => $merchant]);
            DB::table('commerce_locks')->where('id', $merchant)->lockForUpdate()->first();
            $existing = DB::table('qris_profiles')->where('merchant_key', $merchant)->first();
            if ($existing) {
                app(CustomerCommerce::class)->ensure((int) $existing->owner_id === (int) $store->owner_id, 'QRIS ini telah terhubung ke owner lain. Hubungi administrator untuk pemeriksaan kepemilikan.');
                $previous = $this->credentials($existing->id, (int) $store->owner_id);
                if (($previous['application_package'] ?? '') !== ($credentials['application_package'] ?? '') || ($previous['application_name'] ?? '') !== ($credentials['application_name'] ?? '')) {
                    app(CustomerCommerce::class)->ensure(! CustomerOrder::where('merchant_key', $merchant)->where('payment_status', 'unpaid')->where('status', 'awaiting_payment')->exists(), 'Selesaikan invoice berjalan pada seluruh toko dengan QRIS ini sebelum mengganti aplikasi penerima.');
                    $previous['application_package'] = $credentials['application_package'] ?? '';
                    $previous['application_name'] = $credentials['application_name'] ?? '';
                    $previous['forward_token'] = Str::random(64);
                    unset($previous['qris_profile_id']);
                    DB::table('qris_profiles')->where('id', $existing->id)->update(['credentials' => Crypt::encryptString(json_encode($previous, JSON_THROW_ON_ERROR)), 'updated_at' => now()]);
                }

                return [...$credentials, ...$this->credentials($existing->id, (int) $store->owner_id)];
            }
            $id = (string) Str::uuid();
            $shared = array_intersect_key($credentials, array_flip(['qris_payload', 'application_package', 'application_name', 'notification_parser']));
            $shared['forward_token'] = Str::random(64);
            DB::table('qris_profiles')->insert(['id' => $id, 'owner_id' => $store->owner_id, 'merchant_key' => $merchant, 'name' => 'QRIS '.$store->name, 'credentials' => Crypt::encryptString(json_encode($shared, JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now()]);

            return [...$credentials, ...$shared, 'qris_profile_id' => $id];
        });
    }

    public function saveParser(string $profileId, int $owner, array $parser): void
    {
        DB::transaction(function () use ($profileId, $owner, $parser) {
            DB::table('qris_profiles')->where('id', $profileId)->lockForUpdate()->firstOrFail();
            $credentials = $this->credentials($profileId, $owner);
            unset($credentials['qris_profile_id']);
            $credentials['notification_parser'] = $parser;
            DB::table('qris_profiles')->where('id', $profileId)->update(['credentials' => Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR)), 'updated_at' => now()]);
        });
    }

    public function reserve(Store $store, string $reference, int $amount, \DateTimeInterface $expires, string $merchant): array
    {
        $excluded = CustomerOrder::where('merchant_key', $merchant)->whereNotNull('active_amount_key')->whereBetween('total', [$amount + 1, $amount + 999])->pluck('total')->map(fn ($total) => (int) $total)->all();

        return app(HanshopIdentity::class)->call('qris/reserve', ['merchant' => $merchant, 'reference' => $reference, 'owner' => $store->owner->hanshop_user_id, 'store' => $store->id, 'amount' => $amount, 'expires_at' => Carbon::instance($expires)->toIso8601String(), 'excluded_totals' => $excluded]);
    }

    public function receive(string $merchant, string $event, int $amount, Carbon $at, string $source): array
    {
        try {
            return app(HanshopIdentity::class)->call('qris/receive', ['merchant' => $merchant, 'event_id' => $event, 'amount' => $amount, 'occurred_at' => $at->toIso8601String(), 'source' => $source]);
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, 'Pencocokan QRIS bersama belum tersedia. Forwarder akan mencoba ulang mutasi yang sama.');
        }
    }

    public function ingest(array $credentials, string $event, int $amount, Carbon $at, string $source): void
    {
        $profile = DB::table('qris_profiles')->where('id', $credentials['qris_profile_id'])->firstOrFail();
        $id = hash('sha256', $profile->merchant_key.':'.$event);
        $receipt = DB::transaction(function () use ($profile, $id, $event, $amount, $at, $source) {
            DB::table('qris_profiles')->where('id', $profile->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('qris_events')->where('id', $id)->first();
            if ($existing) {
                abort_unless((int) $existing->amount === $amount && Carbon::parse($existing->occurred_at)->equalTo($at->copy()->startOfSecond()), 409, 'Identitas mutasi dipakai dengan data berbeda.');

                return json_decode(Crypt::decryptString($existing->receipt), true, 512, JSON_THROW_ON_ERROR);
            }
            if (config('services.forwarder.shared_broker')) {
                $receipt = $this->receive($profile->merchant_key, $event, $amount, $at, $source)['receipt'];
            } else {
                $orders = CustomerOrder::whereHas('store', fn ($query) => $query->where('owner_id', $profile->owner_id))->where('gateway', 'custom')->where('merchant_key', $profile->merchant_key)->where('total', $amount)->where('created_at', '<=', $at)->where('expires_at', '>=', $at)->get();
                $order = $orders->count() === 1 ? $orders->first() : null;
                $receipt = ['id' => $id, 'status' => $order ? 'store' : ($orders->isEmpty() ? 'unmatched' : 'review'), 'reference' => $order?->id, 'store_id' => $order?->store_id, 'amount' => $amount, 'occurred_at' => $at->toIso8601String()];
            }
            $orderId = filled($receipt['reference'] ?? null) && CustomerOrder::whereKey($receipt['reference'])->exists() ? $receipt['reference'] : null;
            DB::table('qris_events')->insert(['id' => $id, 'profile_id' => $profile->id, 'amount' => $amount, 'occurred_at' => $at->copy()->startOfSecond(), 'status' => $receipt['status'], 'order_id' => $orderId, 'receipt' => Crypt::encryptString(json_encode($receipt, JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now()]);

            return $receipt;
        });
        $this->applyReceipt($receipt);
    }

    public function applyReceipt(array $receipt): void
    {
        if (($receipt['status'] ?? null) !== 'store') {
            return;
        }
        $order = CustomerOrder::whereKey($receipt['reference'])->where('store_id', $receipt['store_id'])->where('gateway', 'custom')->first();
        if (! $order && ! Store::whereKey($receipt['store_id'])->exists()) {
            return;
        }
        abort_unless($order && (int) $order->total === (int) $receipt['amount'], 503, 'Pesanan belum siap menerima mutasi. Pengiriman akan dicoba ulang.');
        app(CustomerCommerce::class)->paid($order, 'shared-qris:'.$receipt['id'], (int) $receipt['amount'], Carbon::parse($receipt['occurred_at']));
    }
}
