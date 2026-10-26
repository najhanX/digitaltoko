<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SupplierDeposit extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'encrypted:array', 'is_sandbox' => 'boolean', 'amount' => 'integer', 'checked_at' => 'immutable_datetime'];
    }
}
