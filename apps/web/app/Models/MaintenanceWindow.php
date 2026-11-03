<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceWindow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'ends_at' => 'immutable_datetime'];
    }
}
