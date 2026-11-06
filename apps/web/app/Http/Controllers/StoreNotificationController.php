<?php

namespace App\Http\Controllers;

use App\Mail\StoreMessageMail;
use App\Models\Store;
use App\Services\CustomerOtp;
use App\Services\StoreMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StoreNotificationController extends Controller
{
    private function access(Request $request, Store $store, bool $write = false): string
    {
        $owner = $request->user('web');
        if ($owner?->role === 'owner' && $owner->id === $store->owner_id) {
            abort_unless($owner->canUseOwnerFeatures(), 403);

            return 'owner';
        }
        $staff = $request->user('staff');
        $permissions = $staff?->permissions ?? [];
        abort_unless($staff && $staff->active && $staff->store_id === $store->id && (in_array('notifications.manage', $permissions, true) || (! $write && in_array('notifications.view', $permissions, true))), 403);

        return 'staff';
    }

    public function show(Request $request, Store $store, StoreMail $mail, CustomerOtp $otp): Response
    {
        $role = $this->access($request, $store);
        Inertia::encryptHistory();

        return Inertia::render('Owner/Notifications', ['role' => $role, 'store' => $store->only(['id', 'name', 'category']),
            'canManage' => $role === 'owner' || in_array('notifications.manage', $request->user('staff')->permissions ?? [], true),
            'canViewWhatsapp' => $role === 'owner' || count(array_intersect(['bot.view', 'bot.manage'], $request->user('staff')->permissions ?? [])) > 0,
            'whatsappReady' => $otp->whatsappReady($store), 'emailSettings' => $mail->publicSettings($store)]);
    }

    public function update(Request $request, Store $store, StoreMail $mail): RedirectResponse
    {
        $this->access($request, $store, true);
        $data = $request->validate(['mode' => 'required|in:platform,smtp',
            'host' => 'required_if:mode,smtp|nullable|string|max:253', 'port' => ['required_if:mode,smtp', 'nullable', 'integer', Rule::in([465, 587])],
            'username' => 'required_if:mode,smtp|nullable|string|max:255', 'password' => 'nullable|string|max:1024',
            'from_address' => 'required_if:mode,smtp|nullable|email:rfc|max:255']);
        if ($data['mode'] === 'smtp') {
            $mail->validateHost($data['host']);
        }
        DB::transaction(function () use ($store, $data) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->notification_settings ?? [];
            if ($data['mode'] === 'platform') {
                $locked->update(['notification_settings' => [...$previous, 'mode' => 'platform']]);

                return;
            }
            $password = filled($data['password'] ?? null) ? $data['password'] : ($previous['password'] ?? null);
            validator(['password' => $password], ['password' => 'required|string'])->validate();
            $locked->update(['notification_settings' => [...$data, 'password' => $password]]);
        });

        return back()->with('success', 'Pengirim email toko tersimpan.');
    }

    public function test(Request $request, Store $store, StoreMail $mail): RedirectResponse
    {
        $this->access($request, $store, true);
        $data = $request->validate(['email' => 'required|email:rfc|max:255']);
        $mail->send($store, $data['email'], new StoreMessageMail($store->name, 'Email pengujian dari '.$store->name.'. Pengirim ini digunakan untuk OTP dan notifikasi pelanggan toko.'));

        return back()->with('success', 'Email uji telah dikirim. Periksa inbox dan folder spam.');
    }
}
