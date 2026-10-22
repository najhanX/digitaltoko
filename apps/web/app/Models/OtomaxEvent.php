<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OtomaxEvent extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['data'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array'];
    }
}
