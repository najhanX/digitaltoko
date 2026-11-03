<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $settings = config('platform.admin', []);
        $settings['email'] = strtolower(trim((string) ($settings['email'] ?? '')));
        $data = Validator::make($settings, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:72'],
        ], [
            'name.required' => 'Isi ADMIN_NAME di .env.',
            'email.required' => 'Isi ADMIN_EMAIL di .env.',
            'email.email' => 'ADMIN_EMAIL harus berupa alamat email yang valid.',
            'password.required' => 'Isi ADMIN_PASSWORD di .env.',
            'password.min' => 'ADMIN_PASSWORD minimal 12 karakter.',
            'password.max' => 'ADMIN_PASSWORD maksimal 72 karakter.',
        ])->validate();

        DB::transaction(function () use ($data): void {
            $admin = User::where('email', $data['email'])->lockForUpdate()->first();
            if ($admin && $admin->role !== 'admin') {
                throw new RuntimeException('ADMIN_EMAIL sudah dipakai akun non-admin. Pilih email lain; akun tersebut tidak diubah.');
            }
            $admin ??= new User;
            $admin->forceFill(['name' => $data['name'], 'email' => $data['email'], 'role' => 'admin']);
            if (! $admin->exists || ! Hash::check($data['password'], $admin->password)) {
                $admin->password = $data['password'];
                $admin->remember_token = null;
            }
            $admin->save();
        }, 3);
        $this->command?->info('Akun admin sesuai konfigurasi .env siap. Masuk melalui /admin/login.');
    }
}
