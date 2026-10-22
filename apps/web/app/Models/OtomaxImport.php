<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OtomaxImport extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'summary' => 'array', 'expires_at' => 'immutable_datetime'];
    }
}
