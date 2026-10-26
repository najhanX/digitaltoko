<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProvisioningCase extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['events' => 'array', 'attempts' => 'integer', 'refund_amount' => 'integer', 'approved_at' => 'immutable_datetime', 'refunded_at' => 'immutable_datetime', 'last_attempt_at' => 'immutable_datetime'];
    }
}
