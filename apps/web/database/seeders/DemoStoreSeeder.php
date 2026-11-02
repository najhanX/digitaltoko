<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use App\Support\PlatformHost;
use App\Support\StaffPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoStoreSeeder extends Seeder
{
    public const PASSWORD = 'DigitalToko-Demo2026!';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Demo hanya untuk local/testing.');
        }
        DB::transaction(function () {
            $this->call(PackagePlanSeeder::class);

            User::firstOrCreate(['email' => 'demo-admin@example.test'], [
                'name' => 'Admin Demo', 'role' => 'admin', 'password' => Hash::make(self::PASSWORD),
            ]);
            $owner = User::firstOrCreate(['email' => 'demo-owner@example.test'], [
                'name' => 'Owner Demo', 'role' => 'owner', 'password' => Hash::make(Str::random(48)),
                'legal_name' => 'Owner Simulasi (Bukan Identitas Asli)',
                'verification_status' => 'approved', 'email_verified_at' => now(),
                'phone_verified_at' => now(), 'reviewed_at' => now(),
            ]);
            $secondOwner = User::firstOrCreate(['email' => 'demo-owner-two@example.test'], [
                'name' => 'Owner Demo Kedua', 'role' => 'owner', 'password' => Hash::make(Str::random(48)),
                'verification_status' => 'unsubmitted',
            ]);
            $scenarios = [
                ['demo-aurora', 'Aurora Digital Demo', 'full', 'galaxy', 'active', 'aurora', 30, false],
                ['demo-nebula', 'Nebula Gaming Demo', 'web', 'galaxy', 'active', 'nebula', 30, false],
                ['demo-atlas', 'Atlas Premium Demo', 'web', 'galaxy', 'active', 'atlas', 30, false],
                ['demo-grace', 'Toko Masa Tenggang Demo', 'full', 'galaxy', 'grace', 'aurora', -3, false],
                ['demo-expired', 'Toko Kedaluwarsa Demo', 'full', 'galaxy', 'suspended', 'nebula', -10, false],
                ['demo-bot', 'Bot Digital Demo', 'bot', 'galaxy', 'active', 'aurora', 30, false],
                ['demo-draft', 'Toko Draft Demo', 'full', 'galaxy', 'draft', 'atlas', null, false],
            ];
            foreach ($scenarios as [$slug, $name, $category, $plan, $status, $template, $days, $trial]) {
                $store = Store::firstOrCreate(['slug' => $slug], [
                    'owner_id' => $owner->id, 'name' => $name, 'category' => 'full',
                    'plan' => $plan, 'status' => $status, 'template' => $template,
                    'expires_at' => $days === null ? null : now()->addDays($days), 'is_trial' => $trial,
                ]);
                if ($store->owner_id !== $owner->id) {
                    throw new \RuntimeException('Slug demo sudah dimiliki owner lain: '.$slug);
                }
                foreach ([
                    'manager' => [array_keys(StaffPermissions::labels()), true],
                    'viewer' => [['dashboard.view', 'products.view', 'orders.view', 'subscription.view'], true],
                    'nonaktif' => [['dashboard.view'], false],
                ] as $username => [$permissions, $active]) {
                    StoreStaff::firstOrCreate(['store_id' => $store->id, 'username' => $username], [
                        'name' => ucfirst($username).' Demo', 'email' => $username.'@example.test',
                        'password' => self::PASSWORD, 'permissions' => $permissions, 'active' => $active,
                    ]);
                }
            }
            Store::firstOrCreate(['slug' => 'demo-owner-two'], [
                'owner_id' => $secondOwner->id, 'name' => 'Draft Owner Kedua Demo',
                'category' => 'full', 'plan' => 'galaxy', 'status' => 'draft', 'template' => 'aurora',
            ]);
        });
        $this->command?->info('Data dummy siap. Akun baru admin/staff memakai password: '.self::PASSWORD);
        $this->command?->line('Admin: '.rtrim(config('app.url'), '/').'/admin/login | demo-admin@example.test');
        $this->command?->line('Owner dummy tidak terhubung Hanshop; login owner tetap memerlukan OTP asli. Data existing tidak direset.');
        foreach (Store::where('slug', 'like', 'demo-%')->get() as $store) {
            $this->command?->line($store->slug
                .($store->supportsWeb() ? ' | '.app(PlatformHost::class)->storeUrl($store) : ' | Bot saja')
                .(StoreStaff::where('store_id', $store->id)->exists()
                    ? ' | Staff: '.rtrim(config('app.url'), '/').'/staff/'.$store->id.'/login' : ' | Belum ada staff'));
        }
    }
}
