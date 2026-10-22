<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OtomaxProfile extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array', 'version' => 'integer'];
    }
}
