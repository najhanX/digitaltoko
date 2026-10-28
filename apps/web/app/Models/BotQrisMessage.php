<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotQrisMessage extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['message_key', 'key_hash'];

    protected function casts(): array
    {
        return ['message_key' => 'encrypted:array', 'revoked_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'order_id');
    }

    public function scopeReadyToRevoke(Builder $query): Builder
    {
        return $query->where(fn (Builder $messages) => $messages
            ->where(fn (Builder $qris) => $qris->where('kind', 'qris')->whereHas('order', fn (Builder $orders) => $orders->where(fn (Builder $eligible) => $eligible
                ->where('payment_status', 'paid')->orWhereIn('status', ['expired', 'failed'])
                ->orWhere(fn (Builder $due) => $due->where('payment_status', 'unpaid')->where('status', 'awaiting_payment')->where('expires_at', '<=', now())))))
            ->orWhere(fn (Builder $updates) => $updates->where('kind', 'order')->whereHas('order', fn (Builder $orders) => $orders->where(fn (Builder $changed) => $changed
                ->whereColumn('customer_orders.status', '!=', 'bot_qris_messages.order_status')
                ->orWhereColumn('customer_orders.payment_status', '!=', 'bot_qris_messages.payment_status')
                ->orWhereRaw("COALESCE((SELECT id FROM customer_notices WHERE customer_notices.order_id = customer_orders.id ORDER BY created_at DESC, id DESC LIMIT 1), 'none') <> bot_qris_messages.revision")))));
    }
}
