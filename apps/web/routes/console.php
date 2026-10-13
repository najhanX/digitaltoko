<?php

use App\Models\Store;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('digitaltoko:worker-token {store}', function () {
    $store = Store::findOrFail($this->argument('store'));
    $token = bin2hex(random_bytes(32));
    $store->update(['worker_token_hash' => hash('sha256', $token)]);
    $this->info('Token worker baru (ditampilkan sekali, token sebelumnya dicabut):');
    $this->line($token);
})->purpose('Rotasi credential worker khusus satu toko; simpan nilai token di .env worker.');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('digitaltoko:admin {email} {--name=Administrator}', function () {
    $email = strtolower(trim($this->argument('email')));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::where('email', $email)->exists()) {
        $this->error('Email tidak valid atau sudah digunakan. Akun yang ada tidak diubah.');

        return 1;
    }
    $password = $this->secret('Password admin (minimal 12 karakter)');
    if (! is_string($password) || strlen($password) < 12) {
        $this->error('Password minimal 12 karakter.');

        return 1;
    }
    $admin = new User;
    $admin->name = $this->option('name');
    $admin->email = $email;
    $admin->password = $password;
    $admin->role = 'admin';
    $admin->save();
    $this->info('Akun admin dibuat. Masuk melalui /admin/login.');
})->purpose('Buat administrator lokal tanpa mengubah akun Hanshop.');

Artisan::command('digitaltoko:purge-rejected-ktp', function () {
    $count = 0;
    User::where('verification_status', 'rejected')->whereNotNull('document_purge_at')
        ->where('document_purge_at', '<=', now())->whereNotNull('ktp_path')->pluck('id')->each(function ($id) use (&$count) {
            DB::transaction(function () use ($id, &$count) {
                $owner = User::whereKey($id)->lockForUpdate()->first();
                if ($owner?->verification_status === 'rejected' && $owner->document_purge_at?->lte(now()) && $owner->ktp_path) {
                    if (Storage::disk('local')->delete($owner->ktp_path)) {
                        $owner->forceFill(['ktp_path' => null, 'document_purge_at' => null])->save();
                        $count++;
                    }
                }
            });
        });
    $this->info($count.' dokumen KTP ditolak dibersihkan.');
})->purpose('Hapus foto KTP ditolak setelah 72 jam; akun dan alasan penolakan tetap disimpan.');

Schedule::command('digitaltoko:purge-rejected-ktp')->everyMinute()->withoutOverlapping();
