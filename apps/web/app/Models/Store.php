<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Store extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $attributes = ['sandbox' => false, 'category' => 'full', 'plan' => 'galaxy'];

    protected $hidden = ['worker_token_hash', 'notification_settings'];

    protected function casts(): array
    {
        return ['closure_requested_at' => 'immutable_datetime', 'retention_reasons' => 'array', 'retention_checked_at' => 'immutable_datetime', 'purge_started_at' => 'immutable_datetime', 'notification_settings' => 'encrypted:array', 'sandbox' => 'boolean', 'admin_suspended_at' => 'immutable_datetime', 'subscription' => 'array', 'starts_at' => 'immutable_datetime', 'ready_at' => 'immutable_datetime', 'trial_requested_at' => 'immutable_datetime', 'branding' => 'array', 'expires_at' => 'immutable_datetime', 'is_trial' => 'boolean'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function domain(): HasOne
    {
        return $this->hasOne(StoreDomain::class);
    }

    public function supportsWeb(): bool
    {
        return in_array($this->category, ['bot', 'web', 'full'], true);
    }

    public function supportsBot(): bool
    {
        return $this->supportsWeb();
    }

    public function acceptsOrders(): bool
    {
        return ! $this->admin_suspended_at && in_array($this->status, ['active', 'grace'], true)
            && $this->expires_at !== null && now()->lt($this->expires_at->addDays($this->is_trial ? 0 : 7));
    }

    public function canConnectBot(): bool
    {
        return ! $this->admin_suspended_at && in_array($this->category, ['bot', 'web', 'full'], true)
            && (($this->status === 'provisioning' && ! $this->is_trial && ! $this->trial_requested_at) || $this->acceptsOrders());
    }
}
