<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class CatalogService extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function categories(): HasMany
    {
        return $this->hasMany(CatalogCategory::class, 'service_id');
    }

    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(CatalogProduct::class, CatalogCategory::class, 'service_id', 'category_id');
    }
}
