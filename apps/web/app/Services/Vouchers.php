<?php

namespace App\Services;

use App\Models\CatalogProduct;
use App\Models\CustomerOrder;
use App\Models\DiscountVoucher;
use App\Models\ModulePurchase;
use App\Models\PlanOption;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Vouchers
{
    public static function code(?string $code): string
    {
        return strtoupper(trim($code ?? ''));
    }

    private function ensure(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['voucher_code' => $message]);
        }
    }

    /** Pending invoices reserve quota until cancellation/expiry is confirmed; paid failures still use the voucher. */
    public function used(DiscountVoucher $voucher, bool $sandbox = false, bool $lock = false): int
    {
        $count = (int) ($sandbox ? $voucher->archived_sandbox_uses : $voucher->archived_uses);
        foreach (['order', 'subscription', 'module'] as $type) {
            $model = match ($type) {
                'order' => CustomerOrder::class,'subscription' => SubscriptionInvoice::class,'module' => ModulePurchase::class,default => null
            };
            if (! $model) {
                continue;
            }
            $table = (new $model)->getTable();
            $query = DB::table('voucher_redemptions as redemption')->leftJoin($table.' as subject', 'subject.id', '=', 'redemption.subject_id')
                ->where('redemption.voucher_id', $voucher->id)->where('redemption.is_sandbox', $sandbox)->where('redemption.subject_type', $type)
                ->where(function ($q) use ($type) {
                    $q->whereNull('subject.id')->orWhereNotIn('subject.status', ['expired', 'cancelled']);
                    if ($type === 'order') {
                        $q->orWhere('subject.payment_status', 'paid');
                    }
                });
            $count += $lock ? $query->lockForUpdate()->get(['redemption.id'])->count() : $query->count();
        }

        return $count;
    }

    /** Percent values are basis points (10% = 1000), allowing exact integer arithmetic. */
    public function quote(?Store $store, ?string $code, int $subtotal, ?CatalogProduct $product = null, ?PlanOption $plan = null, bool $module = false, bool $lock = false): ?array
    {
        $code = self::code($code);
        if ($code === '') {
            return null;
        }
        $query = DiscountVoucher::where('scope_key', $store?->id ?? 'platform')->where('code', $code);
        if ($lock) {
            $query->lockForUpdate();
        }
        $v = $query->first();
        $this->ensure($v && $v->active, 'Voucher tidak tersedia di toko/layanan ini.');
        $this->ensure(! $v->starts_at || $v->starts_at->lte(now()), 'Voucher belum mulai berlaku.');
        $this->ensure(! $v->ends_at || $v->ends_at->gt(now()), 'Voucher sudah berakhir.');
        $this->ensure($subtotal >= $v->min_purchase, 'Belanja minimum voucher Rp'.number_format($v->min_purchase, 0, ',', '.').'.');
        $this->ensure(! $store || ($product && $product->store_id === $store->id), 'Sasaran voucher tidak sesuai toko.');
        $targets = $v->targets ?? [];
        $match = $v->all_targets;
        if ($product) {
            $product->loadMissing('taxonomyCategory');
            $match = $match || in_array($product->id, $targets['products'] ?? [], true) || in_array($product->category_id, $targets['categories'] ?? [], true) || in_array($product->taxonomyCategory?->service_id, $targets['services'] ?? [], true);
        } elseif ($module) {
            $match = $match || in_array('custom_qris', $targets['services'] ?? [], true);
        } elseif ($plan) {
            $match = $match || in_array((string) $plan->id, array_map('strval', $targets['plans'] ?? []), true) || in_array($plan->category, $targets['services'] ?? [], true);
        } else {
            $match = false;
        }
        $this->ensure($match, 'Voucher tidak berlaku untuk pilihan ini.');
        $this->ensure(! $v->usage_limit || $this->used($v, (bool) $store?->sandbox, $lock) < $v->usage_limit, 'Kuota voucher sudah habis atau sedang dipakai invoice lain.');
        $discount = $v->type === 'percent' ? intdiv($subtotal * $v->value, 10000) : $v->value;
        if ($v->max_discount !== null) {
            $discount = min($discount, $v->max_discount);
        }
        if ($store === null) {
            $discount = min($discount, $subtotal);
        }
        $this->ensure($discount > 0 && ($store === null || $discount < $subtotal), 'Potongan voucher harus menyisakan nominal pembayaran lebih dari Rp0.');
        $this->ensure($store !== null || ($subtotal === $discount || $subtotal - $discount >= 1000), 'Total layanan DigitalToko setelah diskon harus Rp0 atau minimal Rp1.000.');

        return ['id' => $v->id, 'code' => $v->code, 'name' => $v->name, 'subtotal' => $subtotal, 'discount' => $discount, 'total' => $subtotal - $discount];
    }

    public function redeem(?array $quote, string $type, string $id, bool $sandbox = false): void
    {
        if (! $quote) {
            return;
        }
        DB::table('voucher_redemptions')->insert(['voucher_id' => $quote['id'], 'subject_type' => $type, 'subject_id' => $id, 'is_sandbox' => $sandbox, 'discount' => $quote['discount'], 'created_at' => now(), 'updated_at' => now()]);
    }
}
