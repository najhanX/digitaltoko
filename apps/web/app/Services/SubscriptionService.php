<?php

namespace App\Services;

use App\Models\PlanOption;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Support\BotConnection;
use App\Support\HanshopIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['subscription' => $message]);
        }
    }

    public function features(string $category, string $plan): array
    {
        return array_values(array_filter([$category !== 'web' ? 'bot' : null, $category !== 'bot' ? 'web' : null, $plan !== 'space' ? 'digiflazz' : null, $plan !== 'orbit' ? 'stock' : null, 'hanshop']));
    }

    public function checkout(Store $store, PlanOption $option, ?string $voucherCode = null, ?string $requestId = null): SubscriptionInvoice
    {
        $invoice = DB::transaction(function () use ($store, $option, $voucherCode, $requestId) {
            $owner = User::whereKey($store->owner_id)->lockForUpdate()->firstOrFail();
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $option = PlanOption::whereKey($option->id)->lockForUpdate()->firstOrFail();
            $this->ensure($owner->verification_status === 'approved' && $owner->email_verified_at && $owner->phone_verified_at && (bool) $owner->hanshop_user_id, 'Lengkapi verifikasi owner terlebih dahulu.');
            $this->ensure($option->active, 'Paket tidak tersedia.');
            $this->ensure(! $store->admin_suspended_at && ! in_array($store->status, ['purging', 'suspended_manual'], true), 'Toko ini perlu ditinjau admin.');
            if ($requestId) {
                $previous = SubscriptionInvoice::where('store_id', $store->id)->where('snapshot->request_id', $requestId)->lockForUpdate()->first();
                if ($previous) {
                    $this->ensure((int) ($previous->snapshot['option_id'] ?? 0) === $option->id && Vouchers::code($previous->snapshot['voucher']['code'] ?? '') === Vouchers::code($voucherCode), 'Permintaan sudah dipakai untuk pilihan lain. Muat ulang halaman.');

                    return $previous;
                }
            }
            $open = SubscriptionInvoice::where('store_id', $store->id)->whereIn('status', ['pending', 'paid', 'review'])->first();
            if ($open) {
                $this->ensure($open->status === 'pending' && array_intersect_key([...$open->snapshot, 'price' => $open->snapshot['base_price'] ?? $open->snapshot['price']], $option->snapshot()) === $option->snapshot() && Vouchers::code($open->snapshot['voucher']['code'] ?? '') === Vouchers::code($voucherCode), 'Selesaikan invoice atau perubahan paket yang masih berjalan.');

                return $open;
            }
            $purpose = $this->purpose($store, $option);

            $voucher = app(Vouchers::class)->quote(null, $voucherCode, $option->price, plan: $option, lock: true);
            $this->ensure(! $voucher || $voucher['total'] > 0 || filled($requestId), 'Muat ulang halaman sebelum menggunakan voucher gratis.');
            $snapshot = [...$option->snapshot(), 'option_id' => $option->id, 'request_id' => $requestId];
            if ($voucher) {
                $snapshot = [...$snapshot, 'base_price' => $option->price, 'price' => $voucher['total'], 'voucher' => $voucher];
            }
            $invoice = SubscriptionInvoice::create(['store_id' => $store->id, 'owner_id' => $owner->id, 'snapshot' => $snapshot, 'purpose' => $purpose]);
            app(Vouchers::class)->redeem($voucher, 'subscription', $invoice->id);

            return $invoice;
        });

        return $this->sync($invoice);
    }

    public function purpose(Store $store, PlanOption $option): string
    {
        if (! $store->starts_at && ! $store->expires_at) {
            return 'initial';
        }
        if (! $store->is_trial && $store->category === $option->category && $store->plan === $option->plan) {
            return 'renew';
        }
        if (! $store->is_trial && $store->expires_at?->isFuture()
            && array_diff($this->features($store->category, $store->plan), $this->features($option->category, $option->plan))) {
            return 'downgrade';
        }

        return 'change';
    }

    public function sync(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        if ($invoice->status !== 'pending') {
            return $invoice;
        }
        $owner = User::findOrFail($invoice->owner_id);
        $free = (int) $invoice->snapshot['price'] === 0 && (int) ($invoice->snapshot['voucher']['discount'] ?? 0) > 0;
        $result = $free ? ['invoice' => ['invoice_id' => $invoice->id, 'id_user' => $owner->hanshop_user_id, 'amount' => 0, 'total' => 0, 'id_payment' => 'VOUCHER-'.$invoice->id, 'status' => 'PAID', 'paid_at' => now()->toIso8601String(), 'expires_at' => now()->toIso8601String(), 'qr_url' => null]] : app(HanshopIdentity::class)->call('billing/invoice', ['invoice_id' => $invoice->id, 'id_user' => $owner->hanshop_user_id, 'amount' => $invoice->snapshot['price']]);
        $data = $result['invoice'] ?? [];
        validator($data, ['invoice_id' => 'required|in:'.$invoice->id, 'id_user' => 'required|in:'.$owner->hanshop_user_id,
            'amount' => 'required|integer|in:'.$invoice->snapshot['price'], 'id_payment' => 'required|string|max:64',
            'total' => 'required|integer|min:'.$invoice->snapshot['price'], 'status' => 'required|in:PENDING,PAID,EXPIRED,REVIEW',
            'expires_at' => 'required|date', 'paid_at' => 'nullable|date', 'qr_url' => 'nullable|url:https'])->validate();
        $this->ensure($data['total'] <= $invoice->snapshot['price'] + 999, 'Nominal gateway tidak sesuai.');
        if ($data['status'] === 'PAID') {
            $this->ensure(! empty($data['paid_at']) && Carbon::parse($data['paid_at'])->lte(now()->addMinute()), 'Waktu pembayaran tidak valid.');
        }
        DB::transaction(function () use ($invoice, $data, $free) {
            $owner = User::whereKey($invoice->owner_id)->lockForUpdate()->firstOrFail();
            $store = Store::whereKey($invoice->store_id)->lockForUpdate()->firstOrFail();
            StoreRetention::guard($store);
            $locked = SubscriptionInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return;
            }
            $locked->update(['provider_id' => $free ? null : $data['id_payment'], 'total' => $data['total'], 'qr_url' => $data['qr_url'] ?? null, 'expires_at' => $data['expires_at'],
                'status' => ['PENDING' => 'pending', 'PAID' => 'paid', 'EXPIRED' => 'expired', 'REVIEW' => 'review'][$data['status']], 'paid_at' => $data['paid_at'] ?? null, 'last_error' => null]);
            if ($locked->status !== 'pending') {
                $title = match ($locked->status) {
                    'paid' => 'Pembayaran sewa diterima', 'expired' => 'Invoice sewa kedaluwarsa', default => 'Pembayaran sewa perlu ditinjau'
                };
                $message = match ($locked->status) {
                    'paid' => 'Pembayaran paket untuk '.$store->name.' telah diterima. Buka langganan untuk melihat aktivasi atau jadwal berlakunya.',
                    'expired' => 'Batas pembayaran invoice '.$store->name.' telah berakhir. Buka langganan untuk membuat invoice baru.',
                    default => 'Pembayaran untuk '.$store->name.' memerlukan pemeriksaan administrator.',
                };
                app(PanelNotifications::class)->forStore($store, $title, $message, $locked->status === 'paid' ? 'success' : 'warning', 'invoice:'.$locked->id.':'.$locked->status, 'subscription.view');
            }
            if ($locked->status === 'paid') {
                if ($locked->purpose === 'initial') {
                    if (! $owner->trial_used_at && $owner->trial_store_id === $store->id) {
                        $owner->forceFill(['trial_store_id' => null])->save();
                    }
                    $store->update(['category' => $locked->snapshot['category'], 'plan' => $locked->snapshot['plan'], 'status' => 'provisioning', 'ready_at' => null, 'trial_requested_at' => null, 'is_trial' => false]);
                } elseif ($locked->purpose !== 'downgrade' || ! $store->expires_at?->isFuture()) {
                    $base = $locked->purpose === 'renew' && $store->expires_at?->gt($locked->paid_at) ? $store->expires_at : $locked->paid_at;
                    $this->grant($store, $locked, $base);
                }
            }
        });
        $this->tick(Store::findOrFail($invoice->store_id));

        return $invoice->fresh();
    }

    private function grant(Store $store, SubscriptionInvoice $invoice, \DateTimeInterface $base): void
    {
        app(StoreRetention::class)->renewed($store);
        $store->update(['category' => $invoice->snapshot['category'], 'plan' => $invoice->snapshot['plan'], 'subscription' => [...$invoice->snapshot, 'features' => $this->features($invoice->snapshot['category'], $invoice->snapshot['plan']), 'features_version' => 1],
            'starts_at' => $base, 'expires_at' => Carbon::instance($base)->addDays($invoice->snapshot['duration_days']),
            'status' => 'active', 'is_trial' => false, 'trial_requested_at' => null, 'ready_at' => null]);
        $invoice->update(['status' => 'applied', 'applied_at' => now()]);
    }

    public function ready(Store $store): bool
    {
        $bot = BotConnection::state($store, false);

        return ($store->category === 'web' || ($bot['status'] ?? null) === 'CONNECTED') && filled($store->slug);
    }

    public function activate(Store $store): void
    {
        DB::transaction(function () use ($store) {
            $owner = User::whereKey($store->owner_id)->lockForUpdate()->firstOrFail();
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $this->ensure(! $store->admin_suspended_at, 'Toko ditangguhkan administrator. Hubungi administrator sebelum mengaktifkan.');
            if ($store->status === 'active') {
                return;
            }
            $this->ensure($store->status === 'provisioning' && $this->ready($store), 'Toko belum siap. Hubungkan WhatsApp untuk layanan bot.');
            $this->ensure(! $store->is_trial && ! $store->trial_requested_at, 'Trial tidak tersedia. Pilih paket dan gunakan voucher promo jika ada.');
            $invoice = SubscriptionInvoice::where('store_id', $store->id)->where('purpose', 'initial')->where('status', 'paid')->lockForUpdate()->first();
            $this->ensure($invoice !== null, 'Selesaikan pembelian paket sebelum mengaktifkan toko.');
            $this->grant($store, $invoice, now());
        });
    }

    public function tick(Store $store): void
    {
        $auto = false;
        DB::transaction(function () use ($store, &$auto) {
            User::whereKey($store->owner_id)->lockForUpdate()->firstOrFail();
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            if ($store->admin_suspended_at || in_array($store->status, ['suspended_manual', 'purging'], true)) {
                return;
            }
            if ($store->is_trial && ! $store->starts_at && ! $store->expires_at) {
                $store->update(['status' => 'draft', 'is_trial' => false, 'trial_requested_at' => null, 'ready_at' => null]);
                User::whereKey($store->owner_id)->whereNull('trial_used_at')->where('trial_store_id', $store->id)->update(['trial_store_id' => null]);
            }
            if ($store->status === 'provisioning') {
                if ($this->ready($store)) {
                    if (! $store->ready_at) {
                        $store->update(['ready_at' => now()]);
                    }
                    $auto = $store->ready_at->lte(now()->subDay());
                } else {
                    $store->update(['ready_at' => null]);
                }
            }
            if ($store->expires_at && $store->expires_at->lte(now())) {
                $next = SubscriptionInvoice::where('store_id', $store->id)->where('status', 'paid')->where('purpose', 'downgrade')->lockForUpdate()->first();
                if ($next) {
                    $this->grant($store, $next, $store->expires_at);
                }
                $status = $store->expires_at->isFuture() ? 'active' : (! $store->is_trial && $store->expires_at->addDays(7)->isFuture() ? 'grace' : 'expired');
                if ($status !== $store->status && in_array($status, ['grace', 'expired'], true)) {
                    app(PanelNotifications::class)->forStore($store,
                        $status === 'grace' ? 'Toko memasuki masa tenggang' : 'Masa aktif toko berakhir',
                        $status === 'grace' ? $store->name.' memasuki masa tenggang tujuh hari. Transaksi masih tersedia; segera perpanjang langganan.' : 'Layanan '.$store->name.' berhenti karena masa aktif berakhir. Perpanjang langganan untuk melanjutkan.',
                        'warning', 'subscription:'.$store->id.':'.$store->expires_at->timestamp.':'.$status, 'subscription.view');
                }
                $store->update(['status' => $status]);
            }
        });
        if ($auto) {
            $this->activate($store->fresh());
        }
    }
}
