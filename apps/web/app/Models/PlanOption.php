<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanOption extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'price' => 'integer', 'duration_days' => 'integer'];
    }

    public function snapshot(): array
    {
        return $this->only(['name', 'category', 'plan', 'price', 'duration_days']);
    }
}
