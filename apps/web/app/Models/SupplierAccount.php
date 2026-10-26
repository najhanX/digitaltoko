<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SupplierAccount extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['balance' => 'integer', 'needs_check' => 'boolean', 'observed_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'checked_at' => 'immutable_datetime'];
    }
}
