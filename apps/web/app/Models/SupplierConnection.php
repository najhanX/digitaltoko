<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierConnection extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['credentials', 'catalog_config'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'catalog_config' => 'encrypted:array', 'catalog_revision' => 'integer', 'catalog_next_sync_at' => 'immutable_datetime', 'catalog_last_checked_at' => 'immutable_datetime', 'settings' => 'array', 'catalog_modes' => 'array', 'enabled' => 'boolean', 'verified_at' => 'immutable_datetime', 'synced_at' => 'immutable_datetime'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
