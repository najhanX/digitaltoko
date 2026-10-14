<?php

namespace Database\Factories;

use App\Models\CatalogProduct;
use App\Models\PremiumStock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PremiumStock>
 */
class PremiumStockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => CatalogProduct::factory(),
            'store_id' => fn (array $attributes) => CatalogProduct::findOrFail($attributes['product_id'])->store_id,
            'payload' => 'CREDENTIAL SIMULASI — BUKAN AKUN ASLI',
            'cost' => 10000, 'status' => 'ready', 'note' => 'Stok pengujian',
        ];
    }
}
