<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PricingRule extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['auto_update' => 'boolean'];
    }
}
