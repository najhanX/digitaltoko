<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoStoreSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Demo hanya untuk local/testing.');
        }
        $owner = User::firstOrCreate(['email' => 'demo-owner@example.test'], [
            'name' => 'Owner Demo', 'password' => Hash::make(Str::random(48)),
        ]);
        Store::firstOrCreate(['slug' => 'studio'], ['owner_id' => $owner->id, 'name' => 'Studio Digital',
            'category' => 'full', 'plan' => 'galaxy', 'status' => 'draft']);
    }
}
