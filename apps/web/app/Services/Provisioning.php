<?php

namespace App\Services;

use App\Models\ProvisioningCase;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Support\HanshopIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Provisioning
{
    public const HELD = ['review', 'refund_requested', 'refund_processing'];

    public function current(Store $store): ?ProvisioningCase
    {
        return ProvisioningCase::where('store_id', $store->id)->latest()->orderByDesc('id')->first();
    }

    public function monitor(Store $store): ?ProvisioningCase
    {
        return DB::transaction(function () use ($store) {
            User::whereKey($store->owner_id)->lockForUpdate()->firstOrFail();
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $invoice = SubscriptionInvoice::where('store_id', $store->id)->where('purpose', 'initial')->where('status', 'paid')->latest()->lockForUpdate()->first();
            if (! $invoice || $store->starts_at || $store->expires_at || $store->status !== 'provisioning') {
                return $this->current($store);
            }
            $case = ProvisioningCase::firstOrCreate(['invoice_id' => $invoice->id], ['store_id' => $store->id, 'state' => 'waiting_owner', 'refund_amount' => $invoice->total ?? (int) $invoice->snapshot['price']]);
            if (! in_array($case->state, [...self::HELD, 'failed', 'refunded', 'resolved'], true)) {
                $ready = app(SubscriptionService::class)->ready($store);
                $case->update(['state' => $ready ? 'ready' : 'waiting_owner', 'reason' => $ready ? 'Persiapan dasar siap. Owner dapat mengaktifkan toko.' : 'Menunggu subdomain atau WhatsApp toko terhubung. Masa sewa belum berjalan.']);
            }
            if ($invoice->paid_at?->lte(now()->subDay()) && ! in_array($case->state, ['resolved', 'refunded'], true)) {
                $this->notifyAdmin($case, 'Toko lunas belum aktif lebih dari 24 jam', 'stalled');
            }

            return $case->fresh();
        }, 3);
    }

    private function event(ProvisioningCase $case, string $action, string $note, ?User $actor = null): void
    {
        $case->update(['events' => array_slice([...($case->events ?? []), ['action' => $action, 'note' => $note, 'actor' => $actor?->role ?? 'system', 'actor_id' => $actor?->id, 'at' => now()->toIso8601String()]], -40)]);
    }

    private function notifyAdmin(ProvisioningCase $case, string $title, string $event): void
    {
        $store = Store::findOrFail($case->store_id);
        User::where('role', 'admin')->each(fn ($admin) => app(PanelNotifications::class)->send($admin, $title, $store->name.': '.($case->reason ?? 'Perlu ditinjau.'), 'warning', '/admin/stores/'.$store->id, $store, 'provisioning:'.$case->id.':'.$event));
    }

    public function failure(Store $store, string $reason): void
    {
        DB::transaction(function () use ($store, $reason) {
            User::whereKey($store->owner_id)->lockForUpdate()->firstOrFail();
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            if ($store->starts_at || $store->expires_at || $store->status !== 'provisioning') {
                return;
            }
            $case = $this->monitor($store);
            if (! $case || in_array($case->state, [...self::HELD, 'resolved', 'refunded'], true)) {
                return;
            }
            $case->update(['state' => 'failed', 'reason' => $reason]);
            $store->update(['ready_at' => null]);
            $this->event($case, 'failed', $reason);
            $this->notifyAdmin($case, 'Penyiapan toko perlu diperiksa', 'failed');
        }, 3);
    }

    public function held(Store $store): bool
    {
        return ProvisioningCase::where('store_id', $store->id)->whereIn('state', [...self::HELD, 'failed'])->exists();
    }

    public function resolved(Store $store): void
    {
        $case = $this->current($store);
        if ($case && ! in_array($case->state, ['refunded', 'resolved'], true)) {
            $case->update(['state' => 'resolved', 'reason' => 'Toko berhasil aktif.']);
            $this->event($case, 'activated', 'Masa sewa dimulai setelah aktivasi berhasil.');
        }
    }

    /** All actions lock owner, store, invoice, then case, matching subscription activation. */
    public function action(Store $store, User $actor, string $action, string $reason, ?string $expectedCaseId = null): ProvisioningCase
    {
        $this->ensure($actor->role === 'admin' || ($actor->role === 'owner' && $actor->id === $store->owner_id), 'Akses penanganan toko ditolak.');
        $this->monitor($store);
        $case = DB::transaction(function () use ($store, $actor, $action, $reason, $expectedCaseId) {
            User::whereKey($store->owner_id)->lockForUpdate()->firstOrFail();
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            StoreRetention::guard($store);
            $caseId = $this->current($store)?->id;
            $found = ProvisioningCase::findOrFail($caseId);
            $invoice = SubscriptionInvoice::whereKey($found->invoice_id)->lockForUpdate()->firstOrFail();
            $case = ProvisioningCase::whereKey($found->id)->lockForUpdate()->firstOrFail();
            $this->ensure($expectedCaseId === null || $case->id === $expectedCaseId, 'Penanganan toko berubah. Muat ulang sebelum melanjutkan.');
            if ($case->state === 'refunded' && $action === 'approve_refund') {
                return $case;
            }
            $this->ensure(! $store->starts_at && ! $store->expires_at && $store->status === 'provisioning' && $invoice->purpose === 'initial' && $invoice->status === 'paid'
                && ! SubscriptionInvoice::where('store_id', $store->id)->where('status', 'applied')->exists(), 'Penanganan ini hanya untuk sewa awal lunas yang belum pernah aktif.');
            if ($action === 'approve_refund') {
                $this->ensure($actor->role === 'admin' && in_array($case->state, ['failed', 'review', 'refund_requested', 'refund_processing'], true), 'Tinjau kendala sebelum menyetujui refund.');
                $case->update(['state' => 'refund_processing', 'approved_at' => $case->approved_at ?? now(), 'approved_by' => $case->approved_by ?? $actor->id,
                    'refund_amount' => $case->refund_amount ?? $invoice->total ?? (int) $invoice->snapshot['price'], 'admin_note' => $reason, 'reason' => 'Refund disetujui. Menunggu konfirmasi saldo Hanshop.']);
            } else {
                $this->ensure($case->state !== 'refund_processing', 'Refund sedang diproses. Periksa hasil refund sebelum tindakan lain.');
                if ($action === 'retry') {
                    $this->ensure($actor->role === 'admin' || ! in_array($case->state, self::HELD, true), 'Permintaan sedang ditinjau admin. Tunggu hasil pemeriksaan.');
                    $this->ensure(! $case->last_attempt_at || $case->last_attempt_at->lte(now()->subSeconds(30)), 'Tunggu 30 detik sebelum memeriksa ulang.');
                    $ready = app(SubscriptionService::class)->ready($store);
                    $case->update(['state' => $ready ? 'ready' : 'waiting_owner', 'reason' => $ready ? 'Persiapan dasar siap. Lanjutkan aktivasi toko.' : 'Hubungkan WhatsApp toko dan lengkapi subdomain sebelum aktivasi.', 'attempts' => $case->attempts + 1, 'last_attempt_at' => now()]);
                } elseif ($action === 'reject_refund') {
                    $this->ensure($actor->role === 'admin', 'Hanya admin yang dapat menolak refund.');
                    $case->update(['state' => 'failed', 'admin_note' => $reason, 'reason' => 'Refund tidak disetujui. '.$reason]);
                } else {
                    $case->update(['state' => $action === 'request_refund' ? 'refund_requested' : 'review', 'reason' => $reason]);
                }
            }
            $store->update(['ready_at' => null]);
            $this->event($case, $action, $reason, $actor);
            app(PanelNotifications::class)->forStore($store, 'Penanganan persiapan toko', $case->reason, 'info', 'provisioning:'.$case->id.':'.count($case->events ?? []).':'.$case->updated_at->timestamp, 'subscription.view');

            return $case;
        }, 3);
        if (in_array($action, ['report', 'request_refund'], true)) {
            $this->notifyAdmin($case, 'Permintaan penanganan penyiapan toko', 'request:'.$case->updated_at->timestamp);
        }
        if ($action === 'approve_refund' && $case->state === 'refund_processing') {
            $this->refund($case);
        }

        return $case->fresh();
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['provisioning' => $message]);
        }
    }

    public function refund(ProvisioningCase $case): void
    {
        $lock = Cache::lock('provisioning-refund:'.$case->id, 120);
        if (! $lock->get()) {
            return;
        }
        try {
            $case->refresh();
            if ($case->state !== 'refund_processing' || ! $case->approved_at || ! $case->approved_by) {
                return;
            }
            $invoice = SubscriptionInvoice::findOrFail($case->invoice_id);
            $owner = User::findOrFail($invoice->owner_id);
            if ($case->refund_amount > 0) {
                try {
                    $result = app(HanshopIdentity::class)->call('billing/refund', ['invoice_id' => $invoice->id, 'id_user' => $owner->hanshop_user_id, 'amount' => $case->refund_amount, 'admin_id' => (string) $case->approved_by]);
                    $this->ensure(($result['status'] ?? '') === 'REFUNDED' && ($result['invoice_id'] ?? '') === $invoice->id && ($result['id_user'] ?? '') === $owner->hanshop_user_id && (int) ($result['amount'] ?? -1) === $case->refund_amount, 'Konfirmasi refund Hanshop belum cocok.');
                } catch (\Throwable) {
                    $case->update(['reason' => 'Refund sudah disetujui, tetapi belum dikonfirmasi Hanshop. Sistem akan memeriksa ulang tanpa menggandakan kredit.']);
                    $this->notifyAdmin($case, 'Konfirmasi refund sewa tertunda', 'refund-delayed');

                    return;
                }
            }
            DB::transaction(function () use ($case, $invoice) {
                User::whereKey($invoice->owner_id)->lockForUpdate()->firstOrFail();
                $store = Store::whereKey($invoice->store_id)->lockForUpdate()->firstOrFail();
                $invoice = SubscriptionInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $case = ProvisioningCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
                if ($case->state === 'refunded') {
                    return;
                }
                $this->ensure(! $store->starts_at && ! $store->expires_at && $invoice->status === 'paid', 'Data aktivasi berubah. Refund memerlukan rekonsiliasi admin.');
                $invoice->update(['status' => 'refunded', 'last_error' => null]);
                $store->update(['status' => 'draft', 'ready_at' => null]);
                $case->update(['state' => 'refunded', 'refunded_at' => now(), 'reason' => $case->refund_amount ? 'Pembayaran dikembalikan ke saldo Hanshop owner, termasuk kode unik.' : 'Paket voucher gratis dibatalkan tanpa kredit saldo.']);
                $this->event($case, 'refunded', $case->reason);
                app(PanelNotifications::class)->forStore($store, 'Penyelesaian sewa awal', $case->reason, 'success', 'provisioning:'.$case->id.':refunded', 'subscription.view');
            }, 3);
        } finally {
            $lock->release();
        }
    }
}
