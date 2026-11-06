<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\Provisioning;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProvisioningController extends Controller
{
    public function update(Request $request, Store $store, Provisioning $service): RedirectResponse
    {
        $actor = $request->user('web');
        $admin = $actor?->role === 'admin';
        abort_unless($admin || ($actor?->role === 'owner' && $actor->id === $store->owner_id && $actor->canUseOwnerFeatures()), 403);
        $data = $request->validate(['case_id' => 'required|uuid', 'action' => $admin ? 'required|in:retry,report,approve_refund,reject_refund' : 'required|in:retry,report,request_refund', 'reason' => 'required|string|min:10|max:1000',
            'platform_fault' => 'required_if:action,approve_refund|accepted_if:action,approve_refund', 'confirmation' => 'required_if:action,approve_refund|nullable|string']);
        if ($data['action'] === 'approve_refund') {
            if (($data['confirmation'] ?? '') !== $store->slug) {
                throw ValidationException::withMessages(['confirmation' => 'Ketik subdomain toko untuk konfirmasi refund.']);
            }
        }
        $service->action($store, $actor, $data['action'], $data['reason'], $data['case_id']);

        return back()->with('success', 'Penanganan penyiapan toko diperbarui. Periksa status dan catatan terbaru.');
    }
}
