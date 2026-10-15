<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrderAttempt extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['request_snapshot', 'result'];

    protected function casts(): array
    {
        return ['request_snapshot' => 'encrypted:array', 'result' => 'encrypted', 'cost' => 'integer', 'last_checked_at' => 'immutable_datetime'];
    }
}
