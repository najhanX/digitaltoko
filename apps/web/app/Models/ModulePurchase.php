<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ModulePurchase extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'amount' => 'integer', 'total' => 'integer', 'expires_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime'];
    }
}
