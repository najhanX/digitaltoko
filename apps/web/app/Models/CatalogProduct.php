<?php

namespace App\Models;

use App\Services\CatalogTaxonomy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogProduct extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(fn (CatalogProduct $product) => app(CatalogTaxonomy::class)->assign($product));
    }

    public function taxonomyCategory(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class, 'category_id');
    }

    protected function casts(): array
    {
        return ['sale_price' => 'integer', 'active' => 'boolean', 'auto_update' => 'boolean'];
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ProductMapping::class, 'product_id');
    }

    public function readyStocks(): HasMany
    {
        return $this->hasMany(PremiumStock::class, 'product_id')->where('status', 'ready');
    }
}
