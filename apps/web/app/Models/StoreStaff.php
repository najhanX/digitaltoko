<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class StoreStaff extends Authenticatable
{
    protected $table = 'store_staff';

    protected $attributes = ['active' => true];

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'active' => 'boolean', 'password' => 'hashed'];
    }
}
