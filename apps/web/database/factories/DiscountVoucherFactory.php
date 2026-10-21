<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DiscountVoucherFactory extends Factory
{
    public function definition(): array
    {
        return ['scope_key' => 'platform', 'code' => strtoupper(fake()->unique()->bothify('PROMO########')), 'name' => 'Promo uji', 'active' => true, 'all_targets' => true, 'type' => 'fixed', 'value' => 1000, 'min_purchase' => 0, 'targets' => []];
    }
}
