<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProduct extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['billing' => 'array', 'cost' => 'integer', 'available' => 'boolean', 'quantity' => 'integer', 'observed_at' => 'immutable_datetime', 'price_observed_at' => 'immutable_datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SupplierConnection::class, 'connection_id');
    }
}
