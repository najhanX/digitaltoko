<?php

namespace Database\Factories;

use App\Models\CatalogProduct;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogProduct>
 */
class CatalogProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => fn () => Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved'])->id,
                'name' => 'Toko Simulasi', 'slug' => 'catalog-'.fake()->unique()->bothify('??????????'), 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'template' => 'aurora', 'expires_at' => now()->addDays(30)])->id,
            'code' => strtoupper(fake()->unique()->bothify('P-????????')),
            'name' => fake()->words(3, true),
            'kind' => 'premium', 'service' => 'Aplikasi premium', 'category' => 'Premium simulasi', 'active' => true, 'sale_price' => 20000,
        ];
    }
}
