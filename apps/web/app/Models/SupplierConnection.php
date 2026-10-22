<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierConnection extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'settings' => 'array', 'catalog_modes' => 'array', 'enabled' => 'boolean', 'verified_at' => 'immutable_datetime', 'synced_at' => 'immutable_datetime'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
