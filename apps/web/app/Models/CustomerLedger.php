<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomerLedger extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['is_sandbox' => 'boolean', 'metadata' => 'array', 'amount' => 'integer', 'balance_after' => 'integer'];
    }
}
