<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogCategory extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function service(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class, 'service_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(CatalogProduct::class, 'category_id');
    }
}
