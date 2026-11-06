<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Services\StoreDomains;
use App\Support\PlatformHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StoreDomainController extends Controller
{
    private function owner(Request $request, Store $store): void
    {
        abort_unless($request->user()?->role === 'owner', 403);
        abort_unless($request->user()->id === $store->owner_id, 404);
        abort_unless($request->user()->canUseOwnerFeatures() && $store->supportsWeb() && ! $store->purge_started_at, 403);
    }

    public function save(Request $request, Store $store, StoreDomains $domains): RedirectResponse
    {
        $this->owner($request, $store);
        $data = $request->validate(['hostname' => 'required|string|max:253']);
        $domains->save($store, $data['hostname']);

        return back()->with('success', 'Domain disimpan. Tambahkan record DNS lalu verifikasi. Subdomain bawaan tetap tersedia.');
    }

    public function verify(Request $request, Store $store, StoreDomains $domains): RedirectResponse
    {
        $this->owner($request, $store);
        $domain = $store->domain()->firstOrFail();
        $domains->verify($domain);
        $domain->refresh();

        return back()->with($domain->status === 'active' ? 'success' : 'warning', $domain->status === 'active' ? 'Domain aktif. Alamat toko sudah menggunakan domain pribadi.' : ($domain->last_error ?? 'Domain belum siap. Periksa petunjuk DNS.'));
    }

    public function remove(Request $request, Store $store): RedirectResponse
    {
        $this->owner($request, $store);
        $store->domain()->delete();

        return back()->with('success', 'Koneksi domain dilepas. Toko kembali menggunakan subdomain bawaan.');
    }

    public function proof(Request $request): JsonResponse
    {
        $domain = StoreDomain::with('store')->where('hostname', strtolower($request->getHost()))
            ->whereIn('status', ['pending_tls', 'active'])->whereNotNull('dns_verified_at')->first();
        abort_unless($domain && $domain->verification_token && $domain->store->supportsWeb() && ! $domain->store->purge_started_at, 404);

        return response()->json(['hostname' => $domain->hostname, 'token' => $domain->verification_token])->header('Cache-Control', 'no-store');
    }

    public function tlsAsk(Request $request, string $key): Response
    {
        $configured = (string) config('domains.tls_ask_key');
        abort_unless(strlen($configured) >= 32 && hash_equals($configured, $key), 403);
        $input = $request->query('domain');
        abort_unless(is_string($input) && strlen($input) <= 253, 403);
        $hostname = strtolower(rtrim($input, '.'));
        $hosts = app(PlatformHost::class);
        if (in_array($hostname, $hosts->domains(), true)) {
            return response('allowed', 200)->header('Cache-Control', 'no-store');
        }
        foreach ($hosts->domains() as $platform) {
            if (str_ends_with($hostname, '.'.$platform)) {
                $store = $hosts->resolveStore($hostname);
                abort_unless($store && $store->supportsWeb() && ! $store->purge_started_at, 403);

                return response('allowed', 200)->header('Cache-Control', 'no-store');
            }
        }
        $domain = StoreDomain::with('store')->where('hostname', $hostname)
            ->whereIn('status', ['pending_tls', 'active'])->whereNotNull('dns_verified_at')->first();
        abort_unless($domain && $domain->verification_token && $domain->store->supportsWeb() && ! $domain->store->purge_started_at, 403);

        return response('allowed', 200)->header('Cache-Control', 'no-store');
    }
}
