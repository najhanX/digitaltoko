<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerOrder extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['guest_checkout_hash', 'payment_credentials', 'qr_payload', 'qr_image', 'result', 'request_hash', 'idempotency_key', 'active_amount_key', 'merchant_key'];

    protected function casts(): array
    {
        return ['is_sandbox' => 'boolean', 'snapshot' => 'array', 'resolution' => 'array', 'payment_credentials' => 'encrypted:array', 'result' => 'encrypted', 'amount' => 'integer', 'fee' => 'integer', 'total' => 'integer', 'credit_amount' => 'integer', 'refund_amount' => 'integer', 'expires_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'refunded_at' => 'immutable_datetime', 'pending_notified_at' => 'immutable_datetime'];
    }

    public static function descriptionText(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_substr($value, 0, 500))) : '';
    }

    public function supplierDescription(): ?string
    {
        $text = self::descriptionText($this->snapshot['supplier_description']['text'] ?? null);

        return $this->purpose === 'product' && $text !== '' ? $text : null;
    }

    public function recordSupplierDescription(OrderAttempt $attempt, mixed $value): void
    {
        $snapshot = $this->snapshot ?? [];
        $text = self::descriptionText($value);
        if ($text === '') {
            unset($snapshot['supplier_description']);
        } else {
            $snapshot['supplier_description'] = ['attempt_id' => $attempt->id, 'text' => $text];
        }
        $this->update(['snapshot' => $snapshot]);
        $attempt->update(['request_snapshot' => [...($attempt->request_snapshot ?? []), 'supplier_description' => $text]]);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(OrderAttempt::class, 'order_id');
    }
}
