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

    protected $hidden = ['payment_credentials', 'qr_payload', 'qr_image', 'result', 'request_hash', 'idempotency_key', 'active_amount_key', 'merchant_key'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'resolution' => 'array', 'payment_credentials' => 'encrypted:array', 'result' => 'encrypted', 'amount' => 'integer', 'fee' => 'integer', 'total' => 'integer', 'credit_amount' => 'integer', 'refund_amount' => 'integer', 'expires_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'refunded_at' => 'immutable_datetime', 'pending_notified_at' => 'immutable_datetime'];
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
