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
