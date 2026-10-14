<?php

namespace Database\Factories;

use App\Models\PanelNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PanelNotification>
 */
class PanelNotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(), 'staff_id' => null,
            'title' => 'Pengaturan diperbarui', 'message' => 'Perubahan berhasil disimpan.',
            'level' => 'success', 'url' => '/owner', 'read_at' => null,
        ];
    }
}
