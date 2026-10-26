<?php

namespace App\Services;

use App\Models\CustomerOrder;
use App\Models\OrderAttempt;
use App\Models\Store;
use App\Models\SupplierAccount;
use App\Models\SupplierConnection;
use App\Models\SupplierDeposit;
use App\Support\HanshopIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class SupplierWallets
{
    public function minimumDeposit(SupplierConnection $connection): int
    {
        return $connection->kind === 'digiflazz' ? 200000 : 10000;
    }

    public function identity(SupplierConnection $connection): string
    {
        $credentials = $connection->credentials ?? [];
        $identity = match ($connection->kind) {
            'hanshop' => [config('services.hanshop.url'), $connection->store->owner->hanshop_user_id],
            'digiflazz' => [$credentials['username'] ?? '', $credentials[$connection->store->sandbox ? 'development_key' : 'api_key'] ?? ''],
            default => [rtrim(strtolower($connection->settings['base_url'] ?? ''), '/'), $credentials['member_id'] ?? '', $credentials['pin'] ?? '', $credentials['password'] ?? ''],
        };

        return hash_hmac('sha256', json_encode([$connection->store->owner_id, $connection->kind, $connection->store->sandbox, $identity]), (string) config('app.key'));
    }

    public function account(SupplierConnection $connection): SupplierAccount
    {
        return SupplierAccount::firstOrCreate(['identity_key' => $this->identity($connection)], ['store_id' => $connection->store_id]);
    }

    /** Supplier event time is distinct from the time an HTTP response reached us. */
    public function observe(SupplierConnection $connection, mixed $amount, ?Carbon $time, string $source): void
    {
        if (! is_numeric($amount) || ! is_finite((float) $amount) || abs((float) $amount) > 1000000000000) {
            return;
        }
        $account = $this->account($connection);
        DB::transaction(function () use ($account, $amount, $time, $source) {
            $account = SupplierAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (! $time || $time->isAfter(now()->addSeconds(30))) {
                $account->update(['needs_check' => true]);

                return;
            }
            if ($account->observed_at && $time->lt($account->observed_at)) {
                return;
            }
            if ($account->observed_at && $time->equalTo($account->observed_at)) {
                if ($account->balance !== null && $account->balance !== (int) $amount) {
                    $account->update(['needs_check' => true]);
                }

                return;
            }
            $account->update(['balance' => (int) $amount, 'observed_at' => $time, 'received_at' => now(), 'source' => $source, 'needs_check' => false]);
        }, 3);
    }

    public function eventTime(array $fields, string $timezone = 'Asia/Jakarta'): ?Carbon
    {
        try {
            if (filled($fields['observed_at'] ?? null) && is_string($fields['observed_at']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $fields['observed_at'])) {
                return Carbon::parse($fields['observed_at']);
            }
            $date = $fields['tanggal'] ?? '';
            $time = $fields['jam'] ?? '';
            if (! is_string($date) || ! is_string($time) || ! preg_match('~^(\d{1,2})/(\d{1,2})(?:/(\d{4}))?$~D', $date, $parts) || ! preg_match('/^\d{2}:\d{2}:\d{2}$/D', $time)) {
                return null;
            }
            $year = isset($parts[3]) ? (int) $parts[3] : (int) now($timezone)->year;
            $candidates = [];
            foreach (isset($parts[3]) ? [$year] : [$year - 1, $year, $year + 1] as $candidate) {
                if (checkdate((int) $parts[2], (int) $parts[1], $candidate)) {
                    $value = Carbon::createFromFormat('!Y-n-j H:i:s', $candidate.'-'.(int) $parts[2].'-'.(int) $parts[1].' '.$time, $timezone);
                    if ($value && $value->format('H:i:s') === $time) {
                        $candidates[] = $value;
                    }
                }
            }
            usort($candidates, fn ($a, $b) => abs($a->timestamp - now()->timestamp) <=> abs($b->timestamp - now()->timestamp));

            return isset($candidates[0]) && abs($candidates[0]->timestamp - now()->timestamp) <= 90 * 86400 ? $candidates[0] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Only call after the transaction reference/product/destination have been authenticated. */
    public function transaction(CustomerOrder $order, OrderAttempt $attempt, array $fields, string $source): void
    {
        if ($order->is_sandbox) {
            return;
        }
        try {
            $snapshot = $attempt->request_snapshot;
            $connection = SupplierConnection::with('store.owner')->where('store_id', $order->store_id)->where('kind', $attempt->source)->find($snapshot['connection_id'] ?? null);
            if (! $connection) {
                return;
            }
            $old = clone $connection;
            if ($attempt->source === 'otomax') {
                $old->credentials = $snapshot['otomax']['credentials'];
                $old->settings = $snapshot['otomax']['settings'];
            } elseif ($attempt->source === 'digiflazz') {
                $old->credentials = ['username' => $snapshot['credentials']['username'], 'api_key' => $snapshot['credentials']['api_key']];
            } elseif (($snapshot['id_user'] ?? '') !== $connection->store->owner->hanshop_user_id) {
                return;
            }
            if ($this->identity($old) !== $this->identity($connection)) {
                return;
            }
            $amount = $attempt->source === 'otomax' ? OtomaxParser::amount($fields['saldo_akhir'] ?? $fields['saldo'] ?? null) : ($fields['buyer_last_saldo'] ?? $fields['balance'] ?? null);
            $this->observe($connection, $amount, $this->eventTime($fields, $snapshot['otomax']['settings']['timezone'] ?? 'Asia/Jakarta'), $source);
        } catch (\Throwable $error) {
            report($error);
        }
    }

    public function refresh(SupplierConnection $connection): SupplierAccount
    {
        $account = $this->account($connection);
        try {
            $started = now();
            if ($connection->kind === 'otomax') {
                $snapshot = app(SupplierCache::class)->remember('otomax', $this->identity($connection), 'wallet-balance', function () use ($connection) {
                    $requestedAt = now()->toIso8601String();

                    return [...app(OtomaxClient::class)->send($connection, 'balance'), 'requested_at' => $requestedAt];
                });
                $amount = OtomaxParser::amount($snapshot['value']['fields']['saldo'] ?? $snapshot['value']['fields']['saldo_akhir'] ?? null);
                if ($amount === null || ! empty($snapshot['value']['error'])) {
                    throw ValidationException::withMessages(['supplier' => 'Saldo belum tertangkap. Periksa penangkap jawaban saldo supplier.']);
                }
                $time = Carbon::parse($snapshot['value']['requested_at'] ?? $snapshot['observed_at']);
            } else {
                $snapshot = app(SupplierCatalog::class)->balance($connection);
                $amount = $snapshot['amount'];
                $time = Carbon::parse($snapshot['requested_at'] ?? $snapshot['observed_at']);
            }
            // A cached response must not displace events newer than the original balance request.
            $this->observe($connection, $amount, $time->min($started), $connection->store->sandbox ? 'simulation' : 'balance');
            $account->update(['checked_at' => now(), 'last_error' => null]);
            if ($connection->kind === 'otomax') {
                $connection->update(['verified_at' => $connection->store->sandbox ? null : now()]);
            }
        } catch (ValidationException $error) {
            $account->update(['checked_at' => now(), 'last_error' => mb_substr(collect($error->errors())->flatten()->first(), 0, 500), 'needs_check' => true]);
            throw $error;
        }

        return $account->fresh();
    }

    public function context(SupplierConnection $connection): array
    {
        return ['id_user' => $connection->store->owner->hanshop_user_id, 'store_id' => $connection->store_id, 'access_until' => now()->addMinutes(5)->toIso8601String()];
    }

    public function deposit(SupplierConnection $connection, array $data): SupplierDeposit
    {
        if ((int) $data['amount'] < $this->minimumDeposit($connection)) {
            throw ValidationException::withMessages(['amount' => 'Minimal deposit '.ucfirst($connection->kind).' adalah Rp'.number_format($this->minimumDeposit($connection), 0, ',', '.').'.']);
        }
        $account = $this->account($connection);
        $record = DB::transaction(function () use ($connection, $data, $account) {
            StoreRetention::guard(Store::whereKey($connection->store_id)->lockForUpdate()->firstOrFail());
            $existing = SupplierDeposit::where('store_id', $connection->store_id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->identity_key === $account->identity_key && $existing->amount === (int) $data['amount'], 422, 'Permintaan deposit berbeda dari permintaan sebelumnya.');

                return $existing;
            }

            return SupplierDeposit::create(['store_id' => $connection->store_id, 'connection_id' => $connection->id, 'identity_key' => $account->identity_key,
                'request_id' => $data['request_id'], 'supplier_name' => $connection->name ?: ucfirst($connection->kind), 'kind' => $connection->kind, 'is_sandbox' => $connection->store->sandbox, 'amount' => $data['amount'],
                'details' => ['bank' => $data['bank'] ?? null, 'owner_name' => $data['owner_name'] ?? null]]);
        }, 3);
        if (! $record->wasRecentlyCreated) {
            return $record;
        }
        try {
            if ($record->is_sandbox) {
                $details = ['message' => 'Simulasi tiket deposit. Tidak ada uang nyata atau saldo produksi yang berubah.', 'total' => $record->amount];
                $status = 'simulated';
            } elseif ($connection->kind === 'hanshop') {
                $details = app(HanshopIdentity::class)->call('supplier/deposit', [...$this->context($connection), 'request_id' => $record->id, 'amount' => $record->amount]);
                $status = $this->depositStatus($details['status'] ?? '');
            } elseif ($connection->kind === 'digiflazz') {
                $credentials = app(StoreEnvironment::class)->credentials($connection);
                $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20)->post('https://api.digiflazz.com/v1/deposit', [
                    'username' => $credentials['username'], 'amount' => $record->amount, 'bank' => $data['bank'], 'owner_name' => $data['owner_name'], 'sign' => md5($credentials['username'].$credentials['api_key'].'deposit')]);
                $body = $response->json('data');
                if (is_array($body) && in_array((string) ($body['rc'] ?? ''), ['40', '41', '42', '45', '64', '82', '88'], true)) {
                    $reason = (string) $body['rc'] === '64' ? 'Tiket deposit tidak berhasil dibuat. Coba nominal lain sesuai ketentuan atau hubungi Digiflazz.' : 'Periksa akun, key, whitelist IP dan metode pembayaran.';
                    $record->update(['status' => 'failed', 'details' => ['rc' => (string) $body['rc'], 'message' => 'Permintaan deposit ditolak Digiflazz (RC '.$body['rc'].'). '.$reason], 'checked_at' => now()]);

                    return $record->fresh();
                }
                if (! $response->successful() || ! is_array($body) || ($body['rc'] ?? '') !== '00' || ! is_numeric($body['amount'] ?? null) || ! is_string($body['account_no'] ?? null)) {
                    throw new \RuntimeException('Deposit response is uncertain.');
                }
                $details = ['total' => (int) $body['amount'], ...array_intersect_key($body, array_flip(['bank', 'account_no', 'notes', 'payment_method']))];
                $status = 'instructions';
            } else {
                $result = app(OtomaxClient::class)->send($connection, 'ticket', ['amount' => $record->amount]);
                $details = ['message' => $result['text']];
                $status = $result['status'] === 'failed' && ! $result['error'] ? 'failed' : 'instructions';
            }
            $record->update(['status' => $status, 'details' => $details, 'checked_at' => now()]);
        } catch (\Throwable $error) {
            $message = $error instanceof SupplierFailure ? $error->getMessage() : 'Hasil permintaan belum pasti. Periksa pemasok sebelum membuat tiket baru.';
            $record->update(['status' => 'unknown', 'details' => [...$record->details, 'message' => $message], 'checked_at' => now()]);
        }

        return $record->fresh();
    }

    private function depositStatus(string $status): string
    {
        return match ($status) {
            'SUKSES' => 'paid', 'GAGAL' => 'expired', 'PENDING' => 'pending', default => 'unknown'
        };
    }

    public function checkDeposit(SupplierDeposit $deposit, SupplierConnection $connection): void
    {
        if ($deposit->is_sandbox || $deposit->kind !== 'hanshop') {
            return;
        }
        abort_unless($this->identity($connection) === $deposit->identity_key && $connection->store->sandbox === $deposit->is_sandbox, 422, 'Akun atau mode koneksi telah berubah. Periksa deposit pada akun pemasok semula.');
        try {
            $details = app(HanshopIdentity::class)->call('supplier/deposit-status', [...$this->context($connection), 'request_id' => $deposit->id]);
        } catch (SupplierFailure $error) {
            $deposit->update(['details' => [...($deposit->details ?? []), 'message' => $error->getMessage()], 'checked_at' => now()]);
            throw ValidationException::withMessages(['supplier' => $error->getMessage()]);
        }
        $deposit->update(['status' => $this->depositStatus($details['status'] ?? ''), 'details' => $details, 'checked_at' => now()]);
        if ($deposit->status === 'paid') {
            $this->account($connection)->update(['needs_check' => true]);
        }
    }
}
