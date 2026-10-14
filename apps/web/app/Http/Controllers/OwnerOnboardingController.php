<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use App\Support\HanshopIdentity;
use App\Support\OwnerOtp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return Inertia::render('Owner/Onboarding', ['profile' => [
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

    public function emailOtp(Request $request, OwnerOtp $otp): RedirectResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($owner->id)]]);
        $email = strtolower(trim($data['email']));
        $otp->issue($request, 'email', 'email', $email, ['user_id' => $owner->id, 'email' => $email]);

        return back()->with('success', 'OTP verifikasi email dikirim.');
    }

    public function verifyEmail(Request $request, OwnerOtp $otp, HanshopIdentity $hanshop): RedirectResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate(['otp' => 'required|digits:6']);
        $payload = $otp->consume($request, 'email', $data['otp']);
        abort_unless($payload['user_id'] === $owner->id, 403);
        validator(['email' => $payload['email']], ['email' => Rule::unique('users', 'email')->ignore($owner->id)])->validate();
        $confirmed = $hanshop->call('profile', ['id' => $owner->hanshop_user_id, 'email' => $payload['email']])['user'];
        if ($confirmed['id'] !== $owner->hanshop_user_id || strtolower((string) $confirmed['email']) !== $payload['email']) {
            throw ValidationException::withMessages(['email' => 'Sinkronisasi email belum terkonfirmasi. Silakan coba kembali.']);
        }
        $owner->forceFill(['email' => $payload['email'], 'email_verified_at' => now()])->save();

        return back()->with('success', 'Email terverifikasi dan tersimpan pada akun Hanshop.');
    }

    public function submit(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);
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
