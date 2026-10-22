<?php

namespace App\Services;

use App\Jobs\ImportOtomaxProducts;
use App\Jobs\ProcessCustomerOrder;
use App\Jobs\ProcessDigiflazzCallback;
use App\Jobs\SendCustomerNotice;
use App\Models\Customer;
use App\Models\CustomerNotice;
use App\Models\DiscountVoucher;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreRetention
{
    public const TABLES = ['otomax_profiles', 'otomax_imports', 'otomax_tickets', 'otomax_events', 'store_domains', 'store_staff', 'supplier_connections', 'supplier_products', 'catalog_services', 'catalog_categories', 'catalog_products', 'product_mappings', 'premium_stocks', 'pricing_rules', 'customers', 'customer_inquiries', 'customer_orders', 'customer_ledgers', 'customer_notices', 'store_payment_settings', 'subscription_invoices', 'module_purchases', 'store_articles', 'article_assets', 'discount_vouchers', 'panel_notifications', 'store_admin_events'];

    /** Called under the store row lock, including supplier callbacks and wallet mutations. */
    public static function guard(Store $store): void
    {
        abort_if($store->status === 'purging' || $store->purge_started_at !== null, 503, 'Data toko sedang dihapus. Perubahan sementara tidak dapat diproses.');
    }

    public function summary(Store $store, bool $inventory = false): array
    {
        $obligations = app(StoreObligations::class)->inspect($store);
        $reasons = app(StoreObligations::class)->reasons($obligations);
        if ($store->admin_suspended_at) {
            $reasons[] = 'Penangguhan admin memerlukan peninjauan manual';
        }
        $queue = config('queue.connections.'.config('queue.default'), []);
        if (! in_array($queue['driver'] ?? '', ['database', 'sync', 'null'], true)
            || (($queue['driver'] ?? '') === 'database' && (($queue['table'] ?? 'jobs') !== 'jobs' || ($queue['connection'] ?? config('database.default')) !== config('database.default')))) {
            $reasons[] = 'Antrean khusus memerlukan pembersihan manual sebelum penghapusan';
        }
        $manual = $store->status === 'suspended_manual' || $store->purge_origin === 'manual';
        $deadline = $store->expires_at?->addDays(30);
        $due = $deadline && $deadline->lte(now());
        $state = $store->purge_started_at ? ($store->retention_error ? 'error' : 'purging')
            : ($manual ? ($reasons ? 'hold' : 'requested') : (! $store->expires_at || $store->expires_at->gt(now()) ? 'none' : ($due ? ($reasons ? 'hold' : 'ready') : 'scheduled')));
        $result = [
            'state' => $state, 'manual_request' => $manual, 'closure_requested_at' => $store->closure_requested_at?->toIso8601String(),
            'can_delete_manually' => $manual && ! $reasons, 'expires_at' => $store->expires_at?->toIso8601String(),
            'blocked_at' => $store->expires_at?->addDays($store->is_trial ? 0 : 7)->toIso8601String(),
            'review_at' => $deadline?->toIso8601String(), 'checked_at' => $store->retention_checked_at?->toIso8601String(),
            'purge_started_at' => $store->purge_started_at?->toIso8601String(), 'error' => $store->retention_error,
            'reasons' => $reasons, 'obligations' => $obligations, 'automatic_enabled' => (bool) config('platform.store_purge_enabled'),
        ];
        if ($inventory) {
            $result['inventory'] = collect(self::TABLES)->mapWithKeys(fn ($table) => [$table => DB::table($table)->where('store_id', $store->id)->count()])->all();
            $result['directories'] = ['storefront/'.$store->id, 'articles/'.$store->id, 'otomax/'.$store->id];
            $result['manual_token'] = hash_hmac('sha256', json_encode([$store->id, $store->slug, $store->status, $store->expires_at?->timestamp,
                $store->closure_requested_at?->timestamp, $store->purge_origin, $state, $obligations, $reasons, $result['inventory']]), config('app.key'));
        }

        return $result;
    }

    /** Evaluation is safe with automatic deletion disabled and deduplicated per expiry cycle. */
    public function review(Store $store): array
    {
        return DB::transaction(function () use ($store) {
            User::whereKey($store->owner_id)->lockForUpdate()->firstOrFail();
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $summary = $this->summary($store);
            if ($store->purge_started_at) {
                return $summary;
            }
            $store->update(['retention_state' => $summary['state'], 'retention_reasons' => $summary['reasons'], 'retention_checked_at' => now(), 'retention_error' => null]);
            if ($summary['state'] === 'none') {
                return $summary;
            }
            if ($summary['manual_request']) {
                $event = 'closure:'.$store->id.':'.($store->closure_requested_at?->timestamp ?? 0).':'.hash('sha256', json_encode($summary['reasons']));
                $message = $store->name.' mengajukan penghapusan permanen.'.($summary['reasons'] ? ' Ditunda: '.implode('; ', $summary['reasons']).'.' : ' Kewajiban selesai; menunggu persetujuan admin.');
                app(PanelNotifications::class)->forStore($store, 'Permohonan penghapusan toko', $message, 'warning', $event, 'subscription.view');
                User::where('role', 'admin')->each(fn ($admin) => app(PanelNotifications::class)->send($admin, 'Permohonan penghapusan toko', $message, 'warning', '/admin/stores/'.$store->id, $store, $event));

                return $summary;
            }
            $age = $store->expires_at->diffInSeconds(now());
            $stage = match (true) {
                $age >= 30 * 86400 => $summary['state'],
                $age >= 29 * 86400 => 'day29',
                $age >= 21 * 86400 => 'day21',
                $age >= 7 * 86400 || $store->is_trial => 'blocked',
                $age >= 6 * 86400 => 'day6',
                default => 'grace',
            };
            $event = 'retention:'.$store->id.':'.$store->expires_at->timestamp.':'.$stage;
            $title = $summary['state'] === 'hold' ? 'Penghapusan toko ditunda' : ($summary['state'] === 'ready' ? 'Toko siap ditinjau untuk penghapusan' : 'Jadwal retensi toko');
            $message = $store->name.'. Layanan berhenti pada '.($summary['blocked_at'] ?? '-').'. Evaluasi penghapusan pada '.$summary['review_at'].'.';
            if ($summary['state'] === 'hold') {
                $message .= ' Tuntaskan: '.implode('; ', $summary['reasons']).'.';
            }
            app(PanelNotifications::class)->forStore($store, $title, $message, 'warning', $event, 'subscription.view');
            if (in_array($summary['state'], ['hold', 'ready'], true)) {
                User::where('role', 'admin')->each(fn ($admin) => app(PanelNotifications::class)->send($admin, $title, $message, 'warning', '/admin/stores/'.$store->id, $store, $event));
            }
            if (in_array($stage, ['day6', 'day21', 'day29', 'hold'], true)) {
                $contact = $store->owner->phone;
                Customer::where('store_id', $store->id)->where('balance', '>', 0)->each(function ($customer) use ($store, $event, $contact) {
                    CustomerNotice::firstOrCreate(['event' => $event.':'.$customer->id], [
                        'store_id' => $store->id, 'phone' => $customer->phone, 'email' => $customer->email,
                        'message' => $store->name.' belum memperpanjang layanan. Saldo Anda Rp'.number_format($customer->balance, 0, ',', '.').'. Hubungi owner toko'.($contact ? ' melalui WhatsApp '.$contact : '').' untuk penyelesaian saldo. Data toko ditahan sampai kewajiban selesai. Jangan mengirim pembayaran baru.',
                    ]);
                });
            }

            return $summary;
        }, 3);
    }

    /** Caller holds owner and store row locks. Old notices must not be sent after renewal. */
    public function renewed(Store $store): void
    {
        self::guard($store);
        if ($store->expires_at) {
            $prefix = 'retention:'.$store->id.':'.$store->expires_at->timestamp.':';
            $notified = CustomerNotice::where('store_id', $store->id)->where('event', 'like', $prefix.'%')
                ->where(fn ($q) => $q->whereNotNull('wa_sent_at')->orWhereNotNull('email_sent_at'))->get(['phone', 'email'])->unique('phone');
            CustomerNotice::where('store_id', $store->id)->where('event', 'like', $prefix.'%')->delete();
            foreach ($notified as $notice) {
                CustomerNotice::firstOrCreate(['event' => 'resumed:'.$store->id.':'.$store->expires_at->timestamp.':'.hash('sha256', $notice->phone)], [
                    'store_id' => $store->id, 'phone' => $notice->phone, 'email' => $notice->email,
                    'message' => $store->name.' telah memperpanjang layanan. Saldo dan riwayat Anda tetap tersimpan. Pengajuan penyelesaian saldo sebelumnya tetap dapat dilanjutkan dengan owner toko.',
                ]);
            }
        }
        $store->forceFill(['retention_state' => null, 'retention_reasons' => null, 'retention_checked_at' => null, 'retention_error' => null]);
    }

    /** Two phases: an irreversible claim blocks renewal; failures remain retryable with tenant records intact. */
    public function purge(Store $store): bool
    {
        return $this->runPurge($store);
    }

    public function approveManualDeletion(Store $store, User $admin, string $previewToken): bool
    {
        abort_unless($admin->role === 'admin', 403);

        return $this->runPurge($store, true, $previewToken);
    }

    private function runPurge(Store $store, bool $manual = false, ?string $previewToken = null): bool
    {
        if (! $manual && ! config('platform.store_purge_enabled')) {
            throw ValidationException::withMessages(['retention' => 'Penghapusan belum diaktifkan. Periksa pratinjau sebelum mengatur STORE_PURGE_ENABLED=true.']);
        }
        if (! Str::isUuid($store->id)) {
            throw new \LogicException('Invalid store identifier.');
        }
        $claimed = DB::transaction(function () use ($store, $manual, $previewToken) {
            User::whereKey($store->owner_id)->lockForUpdate()->firstOrFail();
            $current = Store::whereKey($store->id)->lockForUpdate()->first();
            if (! $current) {
                return false;
            }
            $summary = $this->summary($current, $manual);
            if ($manual) {
                if (! $summary['can_delete_manually']) {
                    return false;
                }
                if (! is_string($previewToken) || ! hash_equals($summary['manual_token'], $previewToken)) {
                    throw ValidationException::withMessages(['retention' => 'Data toko berubah sejak pratinjau dibuka. Muat ulang pratinjau dan periksa kembali sebelum menghapus.']);
                }
            } elseif ($summary['manual_request'] || (! $current->purge_started_at && $summary['state'] !== 'ready')) {
                return false;
            }
            $current->update(['purge_origin' => $manual ? 'manual' : 'automatic', 'status' => 'purging', 'purge_started_at' => $current->purge_started_at ?? now(), 'retention_state' => 'purging', 'retention_error' => null]);

            return true;
        }, 3);
        if (! $claimed) {
            return false;
        }
        try {
            return DB::transaction(function () use ($store) {
                $owner = User::whereKey($store->owner_id)->lockForUpdate()->firstOrFail();
                $current = Store::whereKey($store->id)->lockForUpdate()->first();
                if (! $current) {
                    return false;
                }
                $reasons = $this->summary($current)['reasons'];
                if ($reasons) {
                    throw new \RuntimeException('Obligations changed after purge claim.');
                }
                // Manager removes both worker registry and session; no acknowledgement means no data deletion.
                $neverConnectedDraft = $current->purge_origin === 'manual' && ! $current->starts_at && ! $current->expires_at
                    && ! $current->bot_phone && ! $current->bot_method && ! $current->bot_connection_id && ! $current->worker_token_hash;
                if (! $neverConnectedDraft) {
                    app(BotManager::class)->command($current, 'disconnect');
                }
                $this->eraseFiles($current);
                $this->eraseRedemptions($current);
                $this->eraseQueuedOrders($current);
                foreach (['customer_orders', 'customer_ledgers', 'customers'] as $table) {
                    DB::table($table)->where('store_id', $current->id)->delete();
                }
                Cache::forget('bot:'.$current->id.':state');
                foreach (Cache::pull('store-otp-keys:'.$current->id, []) as $key) {
                    Cache::forget($key);
                }
                if ($owner->trial_store_id === $current->id) {
                    $owner->forceFill(['trial_store_id' => null])->save();
                }
                $current->delete();

                return true;
            });
        } catch (\Throwable $error) {
            Store::whereKey($store->id)->update(['retention_state' => 'error', 'retention_error' => 'Penghapusan belum selesai. Periksa bot manager, penyimpanan, dan log server; jalankan ulang setelah diperbaiki.']);
            report($error);
            throw $error;
        }
    }

    private function eraseFiles(Store $store): void
    {
        $disk = Storage::disk('local');
        $root = realpath($disk->path(''));
        if ($root === false) {
            throw new \RuntimeException('Storage root is unavailable.');
        }
        foreach (['storefront', 'articles', 'otomax'] as $folder) {
            $parent = $disk->path($folder);
            $target = $disk->path($folder.'/'.$store->id);
            if (is_link($parent) || is_link($target)) {
                throw new \RuntimeException('Refusing linked store directory.');
            }
            if (! file_exists($target)) {
                continue;
            }
            $absolute = realpath($target);
            if ($absolute === false || ! str_starts_with($absolute, $root.DIRECTORY_SEPARATOR) || basename($absolute) !== $store->id) {
                throw new \RuntimeException('Invalid store storage path.');
            }
            if (! $disk->deleteDirectory($folder.'/'.$store->id)) {
                throw new \RuntimeException('Store files could not be removed.');
            }
        }
    }

    /** Preserve only anonymous platform quota counters, never deleted tenant transactions or contacts. */
    private function eraseRedemptions(Store $store): void
    {
        foreach (['order' => 'customer_orders', 'subscription' => 'subscription_invoices', 'module' => 'module_purchases'] as $type => $table) {
            $query = DB::table('voucher_redemptions')->where('subject_type', $type)->whereIn('subject_id', DB::table($table)->where('store_id', $store->id)->select('id'));
            $voucherIds = (clone $query)->distinct()->pluck('voucher_id')->sort();
            foreach ($voucherIds as $id) {
                $voucher = DiscountVoucher::withTrashed()->whereNull('store_id')->whereKey($id)->lockForUpdate()->first();
                if (! $voucher) {
                    continue;
                }
                foreach ([false, true] as $sandbox) {
                    $count = (clone $query)->join($table.' as subject', 'subject.id', '=', 'voucher_redemptions.subject_id')->where('voucher_id', $id)->where('voucher_redemptions.is_sandbox', $sandbox)
                        ->where(function ($q) use ($type) {
                            $q->whereNotIn('subject.status', ['expired', 'cancelled']);
                            if ($type === 'order') {
                                $q->orWhere('subject.payment_status', 'paid');
                            }
                        })->count();
                    if ($count) {
                        $voucher->increment($sandbox ? 'archived_sandbox_uses' : 'archived_uses', $count);
                    }
                }
            }
            $query->delete();
        }
    }

    /** Inspect only known job classes; authenticated decryption returns a string, never an instantiated job. */
    private function eraseQueuedOrders(Store $store): void
    {
        $identifiers = [
            ImportOtomaxProducts::class => ['importId', DB::table('otomax_imports')->where('store_id', $store->id)->pluck('id')],
            ProcessCustomerOrder::class => ['orderId', DB::table('customer_orders')->where('store_id', $store->id)->pluck('id')],
            SendCustomerNotice::class => ['noticeId', DB::table('customer_notices')->where('store_id', $store->id)->pluck('id')],
            ProcessDigiflazzCallback::class => ['attemptId', DB::table('order_attempts')->whereIn('order_id', DB::table('customer_orders')->where('store_id', $store->id)->select('id'))->pluck('id')],
        ];
        foreach (['jobs', 'failed_jobs'] as $table) {
            DB::table($table)->orderBy('id')->lazyById(100)->each(function ($job) use ($table, $identifiers) {
                $payload = json_decode($job->payload, true);
                $class = $payload['data']['commandName'] ?? null;
                if (! isset($identifiers[$class])) {
                    return;
                }
                [$property, $ids] = $identifiers[$class];
                if ($ids->isEmpty()) {
                    return;
                }
                $command = $payload['data']['command'] ?? '';
                if ($class === ProcessDigiflazzCallback::class) {
                    $command = Crypt::decrypt($command);
                }
                if (is_string($command) && $ids->contains(fn ($id) => str_contains($command, 's:'.strlen($property).':"'.$property.'";s:36:"'.$id.'";'))) {
                    DB::table($table)->where('id', $job->id)->delete();
                }
            });
        }
    }
}
