<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use App\Support\HanshopIdentity;
use App\Support\OwnerOtp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OwnerOnboardingController extends Controller
{
    private function owner(Request $request): User
    {
        abort_unless($request->user()?->role === 'owner', 403);

        return $request->user();
    }

    public function show(Request $request): Response
    {
        $owner = $this->owner($request);

        return Inertia::render('Owner/Onboarding', ['identityRequired' => (bool) config('platform.owner_ktp_required'), 'profile' => [
            'id' => $owner->id,
            'name' => $owner->name, 'legal_name' => $owner->legal_name ?? '', 'address' => $owner->address ?? '',
            'birth_place' => $owner->birth_place ?? '', 'birth_date' => $owner->birth_date ?? '',
            'phone' => $owner->phone, 'hanshop_user_id' => $owner->hanshop_user_id,
            'email' => str_ends_with($owner->email, '@identity.invalid') ? '' : $owner->email,
            'email_verified' => (bool) $owner->email_verified_at, 'phone_verified' => (bool) $owner->phone_verified_at,
            'verification_status' => $owner->verification_status, 'rejection_reason' => $owner->rejection_reason,
            'has_document' => (bool) $owner->ktp_path, 'submitted_at' => $owner->submitted_at?->toIso8601String(),
            'can_clean_identity' => ! Store::where('owner_id', $owner->id)->exists(),
        ], 'emailChallengePending' => (bool) $request->session()->get('owner_challenge_email')]);
    }

    public function account(Request $request): Response
    {
        $owner = $this->owner($request);

        return Inertia::render('Owner/Account', ['profile' => [
            'name' => $owner->name, 'phone' => $owner->phone,
            'email' => str_ends_with($owner->email, '@identity.invalid') ? '' : $owner->email,
            'email_verified' => (bool) $owner->email_verified_at,
        ]]);
    }

    public function updateName(Request $request, HanshopIdentity $hanshop): RedirectResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate(['name' => 'required|string|max:100']);
        $this->syncAccount($owner, $hanshop, ['name' => $data['name']]);

        return back()->with('success', 'Nama akun diperbarui.');
    }

    public function phoneOtp(Request $request, OwnerOtp $otp): RedirectResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate(['phone' => 'required|string|max:30']);
        $phone = HanshopIdentity::phone($data['phone']);
        validator(['phone' => $phone], ['phone' => Rule::unique('users', 'phone')->ignore($owner->id)])->validate();
        $otp->issue($request, 'phone', 'wa', $phone, ['user_id' => $owner->id, 'phone' => $phone, 'previous_phone' => $owner->phone]);

        return back()->with('success', 'OTP dikirim ke nomor WhatsApp baru.');
    }

    public function verifyPhone(Request $request, OwnerOtp $otp, HanshopIdentity $hanshop): RedirectResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate(['otp' => 'required|digits:6']);
        $payload = $otp->consume($request, 'phone', $data['otp']);
        abort_unless($payload['user_id'] === $owner->id && $payload['previous_phone'] === $owner->phone, 403);
        $this->syncAccount($owner, $hanshop, ['phone' => $payload['phone']], ['phone' => $payload['previous_phone']]);
        $request->session()->regenerate();

        return back()->with('success', 'Nomor WhatsApp diperbarui dan disinkronkan ke Hanshop.');
    }

    public function emailOtp(Request $request, OwnerOtp $otp): RedirectResponse
    {
        $owner = $this->owner($request);
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($owner->id)]]);
        $email = strtolower(trim($data['email']));
        $otp->issue($request, 'email', 'email', $email, ['user_id' => $owner->id, 'email' => $email, 'previous_email' => $owner->email]);

        return back()->with('success', 'OTP verifikasi email dikirim.');
    }

    public function verifyEmail(Request $request, OwnerOtp $otp, HanshopIdentity $hanshop): RedirectResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate(['otp' => 'required|digits:6']);
        $payload = $otp->consume($request, 'email', $data['otp']);
        abort_unless($payload['user_id'] === $owner->id, 403);
        $this->syncAccount($owner, $hanshop, ['email' => $payload['email']], ['email' => $payload['previous_email'] ?? $owner->email]);

        return back()->with('success', 'Email terverifikasi dan tersimpan pada akun Hanshop.');
    }

    /** @param array<string, string> $changes
     * @param  array<string, string|null>  $expected
     */
    private function syncAccount(User $owner, HanshopIdentity $hanshop, array $changes, array $expected = []): void
    {
        Cache::lock('owner-profile:'.$owner->id, 45)->block(3, function () use ($owner, $hanshop, $changes, $expected): void {
            $current = User::findOrFail($owner->id);
            foreach ($expected as $field => $value) {
                if ($current->{$field} !== $value) {
                    throw ValidationException::withMessages(['otp' => 'Kontak akun berubah. Minta OTP baru.']);
                }
            }
            foreach (['phone', 'email'] as $field) {
                if (isset($changes[$field])) {
                    validator([$field => $changes[$field]], [$field => Rule::unique('users', $field)->ignore($current->id)])->validate();
                }
            }
            $path = isset($changes['email']) ? 'profile' : 'profile/contact';
            $confirmed = $hanshop->call($path, ['id' => $current->hanshop_user_id, ...$changes,
                ...(isset($changes['phone']) ? ['previous_phone' => $current->phone] : [])])['user'];
            if ($confirmed['id'] !== $current->hanshop_user_id) {
                throw ValidationException::withMessages(['identifier' => 'Sinkronisasi profil belum terkonfirmasi. Silakan coba kembali.']);
            }
            if (isset($changes['email']) && empty($confirmed['email_verified_at'])) {
                throw ValidationException::withMessages(['email' => 'Verifikasi email belum dikonfirmasi Hanshop.']);
            }
            foreach ($changes as $field => $value) {
                if ($confirmed[$field] !== $value) {
                    throw ValidationException::withMessages([$field => 'Perubahan profil belum dikonfirmasi Hanshop. Silakan coba kembali.']);
                }
            }
            $current->forceFill([...$changes,
                ...(isset($changes['phone']) ? ['phone_verified_at' => now()] : []),
                ...(isset($changes['email']) ? ['email_verified_at' => now()] : []),
            ])->save();
        });
        $owner->refresh();
    }

    public function submit(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);
        abort_unless(config('platform.owner_ktp_required'), 404, 'Verifikasi KTP sedang dinonaktifkan.');
        abort_unless($owner->email_verified_at && $owner->phone_verified_at && $owner->hanshop_user_id, 403);
        $data = $request->validate(['legal_name' => 'required|string|max:150', 'nik' => 'nullable|digits:16',
            'birth_place' => 'required|string|max:150', 'birth_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'address' => 'required|string|min:10|max:1000', 'ktp' => 'required|image|mimes:jpeg,png,jpg|max:5120']);
        $path = $request->file('ktp')->store('ktp', 'local');
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['ktp' => 'Dokumen belum berhasil disimpan. Silakan coba kembali.']);
        }
        try {
            $oldPath = DB::transaction(function () use ($owner, $data, $path) {
                $locked = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                abort_if(in_array($locked->verification_status, ['pending', 'approved'], true), 409, 'Verifikasi sedang diperiksa atau sudah disetujui.');
                $old = $locked->ktp_path;
                $locked->forceFill(['legal_name' => $data['legal_name'], 'nik' => $data['nik'] ?? null, 'address' => $data['address'],
                    'birth_place' => $data['birth_place'], 'birth_date' => $data['birth_date'],
                    'ktp_path' => $path, 'verification_status' => 'pending', 'submitted_at' => now(), 'reviewed_at' => null,
                    'reviewed_by' => null, 'rejection_reason' => null, 'document_purge_at' => null])->save();

                return $old;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return back()->with('success', 'Data dikirim. Tunggu pemeriksaan KTP oleh admin.');
    }

    public function clean(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);
        $path = DB::transaction(function () use ($owner) {
            $locked = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            abort_if(Store::where('owner_id', $owner->id)->exists(), 409, 'Data diri masih diperlukan oleh toko Anda.');
            $path = $locked->ktp_path;
            $locked->forceFill(['legal_name' => null, 'nik' => null, 'address' => null, 'ktp_path' => null,
                'birth_place' => null, 'birth_date' => null,
                'verification_status' => 'unsubmitted', 'submitted_at' => null, 'reviewed_at' => null,
                'reviewed_by' => null, 'rejection_reason' => null, 'document_purge_at' => null])->save();

            return $path;
        });
        if ($path) {
            Storage::disk('local')->delete($path);
        }

        return back()->with('success', 'Data KTP dibersihkan. Akun Hanshop tetap dipertahankan.');
    }
}
