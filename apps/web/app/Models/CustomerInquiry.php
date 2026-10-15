<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomerInquiry extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['data', 'request_hash', 'request_key'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'amount' => 'integer', 'cost' => 'integer', 'expires_at' => 'immutable_datetime'];
    }
}
