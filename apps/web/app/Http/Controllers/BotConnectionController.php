<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\BotManager;
use App\Support\BotConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class BotConnectionController extends Controller
{
    public function show(Request $request, Store $store, BotManager $manager): Response
    {
        $role = BotConnection::access($request, $store);
        abort_unless(in_array($store->category, ['bot', 'web', 'full'], true), 404);
        $canManage = $role === 'owner' || in_array('bot.manage', $request->user('staff')->permissions ?? [], true);

        Inertia::encryptHistory();

        $response = Inertia::render('Owner/BotConnection', [
            'role' => $role, 'store' => $store->only(['id', 'name', 'status', 'category', 'bot_phone', 'bot_method']),
            'canManage' => $canManage, 'canConnect' => $store->canConnectBot(),
            'managerConfigured' => $manager->configured(), 'connection' => BotConnection::state($store, $canManage),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function connect(Request $request, Store $store, BotManager $manager): RedirectResponse
    {
        $role = BotConnection::access($request, $store, true);
        abort_unless($role !== 'owner' || $request->user('web')->verification_status === 'approved', 403);
        $data = $request->validate(['phone' => ['required', 'string', 'max:30', 'regex:/^[+0-9 ()-]+$/'], 'method' => 'required|in:qr,code']);
        $phone = preg_replace('/\D/', '', $data['phone']);
        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }
        if (! preg_match('/^[1-9][0-9]{7,14}$/', $phone)) {
            throw ValidationException::withMessages(['phone' => 'Masukkan nomor WhatsApp beserta kode negara, misalnya 6281234567890.']);
        }
        $lock = Cache::lock('bot:'.$store->id.':command', 15);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['connection' => 'Permintaan koneksi masih diproses. Tunggu sebentar.']);
        }
        try {
            $store->refresh();
            if (! $store->canConnectBot()) {
                throw ValidationException::withMessages(['connection' => 'Bayar paket atau mulai trial terlebih dahulu. Untuk toko kedaluwarsa, perpanjang langganan.']);
            }
            $token = bin2hex(random_bytes(32));
            $store->update(['bot_phone' => $phone, 'bot_method' => $data['method'], 'bot_connection_id' => (string) Str::uuid(), 'worker_token_hash' => hash('sha256', $token), 'ready_at' => $store->status === 'provisioning' ? null : $store->ready_at]);
            Cache::put('bot:'.$store->id.':state', ['status' => 'CONNECTING', 'connection_id' => $store->bot_connection_id, 'updated_at' => now()->toIso8601String()], 90);
            try {
                $manager->command($store, 'connect', ['phone' => $phone, 'method' => $data['method'], 'connection_id' => $store->bot_connection_id, 'worker_token' => $token]);
            } catch (ValidationException $error) {
                Cache::put('bot:'.$store->id.':state', ['status' => 'ERROR', 'connection_id' => $store->bot_connection_id, 'error' => 'MANAGER_UNAVAILABLE'], 90);
                throw $error;
            }
        } finally {
            $lock->release();
        }

        return back()->with('success', 'Permintaan diterima. Tunggu QR atau kode pairing muncul di halaman ini.');
    }

    public function disconnect(Request $request, Store $store, BotManager $manager): RedirectResponse
    {
        BotConnection::access($request, $store, true);
        $lock = Cache::lock('bot:'.$store->id.':command', 15);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['connection' => 'Permintaan koneksi masih diproses. Tunggu sebentar.']);
        }
        try {
            $manager->command($store, 'disconnect');
            $store->update(['worker_token_hash' => null, 'bot_connection_id' => null, 'ready_at' => $store->status === 'provisioning' ? null : $store->ready_at]);
            Cache::forget('bot:'.$store->id.':state');
        } finally {
            $lock->release();
        }

        return back()->with('success', 'Koneksi dilepas. Hubungkan kembali untuk menggunakan bot.');
    }
}
