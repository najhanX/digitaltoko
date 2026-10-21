<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiscountVoucher extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'all_targets' => 'boolean', 'value' => 'integer', 'min_purchase' => 'integer', 'max_discount' => 'integer', 'usage_limit' => 'integer', 'targets' => 'array', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }
}
