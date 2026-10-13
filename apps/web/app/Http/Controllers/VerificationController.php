<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VerificationController extends Controller
{
    private function reference(User $owner): string
    {
        return hash_hmac('sha256', $owner->id.':'.$owner->ktp_path.':'.$owner->submitted_at?->toIso8601String(), config('app.key'));
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->role === 'admin', 403);

        return Inertia::render('Admin/Verifications', ['owners' => User::where('role', 'owner')
            ->where('verification_status', '!=', 'unsubmitted')
            ->orderByRaw("CASE WHEN verification_status = 'pending' THEN 0 ELSE 1 END")->orderBy('submitted_at')->orderBy('id')
            ->paginate(20)->through(fn (User $owner) => ['id' => $owner->id, 'name' => $owner->name, 'legal_name' => $owner->legal_name,
                'phone' => $owner->phone, 'email' => $owner->email, 'address' => $owner->address,
                'nik' => $owner->nik, 'verification_status' => $owner->verification_status,
                'rejection_reason' => $owner->rejection_reason, 'has_document' => (bool) $owner->ktp_path,
                'submitted_at' => $owner->submitted_at?->toIso8601String(), 'submission_reference' => $this->reference($owner)])]);
    }

    public function document(Request $request, User $owner): BinaryFileResponse
    {
        abort_unless($request->user() && ($request->user()->role === 'admin' || ($request->user()->role === 'owner' && $request->user()->id === $owner->id)), 403);
        abort_unless($owner->ktp_path && Storage::disk('local')->exists($owner->ktp_path), 404);
        if ($request->user()->role === 'admin') {
            abort_unless(is_string($request->query('reference')) && hash_equals($this->reference($owner), $request->query('reference')), 409);
        }

        return response()->file(Storage::disk('local')->path($owner->ktp_path), [
            'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
        ]);
    }

    public function review(Request $request, User $owner): RedirectResponse
    {
        abort_unless($request->user()?->role === 'admin', 403);
        $data = $request->validate(['decision' => 'required|in:approved,rejected',
            'reference' => 'required|string|size:64',
            'reason' => 'required_if:decision,rejected|nullable|string|min:5|max:1000']);
        DB::transaction(function () use ($owner, $data, $request) {
            $locked = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->role === 'owner' && $locked->verification_status === 'pending', 409);
            abort_unless(hash_equals($this->reference($locked), $data['reference']), 409, 'Pengajuan berubah. Muat ulang halaman sebelum memeriksa KTP.');
            abort_unless($locked->email_verified_at && $locked->phone_verified_at && $locked->ktp_path && Storage::disk('local')->exists($locked->ktp_path), 409);
            $locked->forceFill(['verification_status' => $data['decision'], 'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(), 'rejection_reason' => $data['decision'] === 'rejected' ? $data['reason'] : null,
                'document_purge_at' => $data['decision'] === 'rejected' ? now()->addHours(72) : null])->save();
        });

        return back()->with('success', $data['decision'] === 'approved' ? 'KTP owner disetujui untuk seluruh tokonya.' : 'KTP ditolak. Alasan dapat dilihat owner.');
    }
}
