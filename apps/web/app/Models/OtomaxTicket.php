<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OtomaxTicket extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['result' => 'encrypted', 'is_sandbox' => 'boolean', 'amount' => 'integer'];
    }
}
