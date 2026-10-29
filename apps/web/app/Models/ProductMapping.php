<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductMapping extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['postpaid' => 'array', 'active' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class, 'supplier_product_id');
    }
}
