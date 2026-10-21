<?php

namespace App\Http\Controllers;

use App\Models\CatalogProduct;
use App\Models\PremiumStock;
use App\Models\Store;
use App\Models\StoreAdminEvent;
use App\Models\StoreStaff;
use App\Models\SubscriptionInvoice;
use App\Models\SupplierConnection;
use App\Models\User;
use App\Services\PanelNotifications;
use App\Services\StoreRetention;
use App\Services\SubscriptionService;
use App\Support\BotConnection;
use App\Support\ListQuery;
use App\Support\PackageCatalog;
use App\Support\PlatformHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminStoreController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403);
    }

    private function payload(Store $store): array
    {
        return [...$store->only(['id', 'name', 'slug', 'category', 'plan', 'status', 'template', 'is_trial', 'retention_state', 'starts_at', 'expires_at', 'created_at', 'admin_suspended_at', 'admin_suspension_reason']),
            'owner' => $store->owner->only(['id', 'name', 'email', 'phone', 'hanshop_user_id', 'verification_status']),
            'domain' => $store->domain?->only(['hostname', 'status', 'verified_at']),
            'url' => app(PlatformHost::class)->storeUrl($store)];
    }

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);
        $list = new ListQuery($request, ['status' => ['draft', 'provisioning', 'active', 'grace', 'expired', 'suspended', 'suspended_manual', 'purging'],
            'category' => ['bot', 'web', 'full'], 'package' => PackageCatalog::keys(), 'suspension' => ['yes', 'no'], 'retention' => ['scheduled', 'requested', 'hold', 'ready', 'purging', 'error']], 10);
        $query = Store::with(['owner:id,name,email,phone,hanshop_user_id,verification_status', 'domain'])->join('users', 'users.id', '=', 'stores.owner_id')->select('stores.*');
        $ownerId = $request->query('owner');
        $owner = is_string($ownerId) && ctype_digit($ownerId) ? User::where('role', 'owner')->find($ownerId, ['id', 'name', 'phone', 'email']) : null;
        if ($owner) {
            $query->where('stores.owner_id', $owner->id);
        }
        if ($list->filters['suspension'] !== '') {
            $list->filters['suspension'] === 'yes' ? $query->whereNotNull('admin_suspended_at') : $query->whereNull('admin_suspended_at');
        }
        $page = $list->paginate($list->apply(PackageCatalog::filter($query, $list->filters['package']), ['stores.name', 'stores.slug', 'users.name', 'users.phone', 'users.email', 'users.hanshop_user_id'], ['status' => 'stores.status', 'category' => 'stores.category', 'retention' => 'stores.retention_state'])->latest('stores.created_at')->orderByDesc('stores.id'));

        return Inertia::render('Admin/Stores', ['stores' => $page->getCollection()->map(fn ($store) => $this->payload($store)),
            'filters' => $list->filters, 'pagination' => ListQuery::meta($page), 'selectedOwner' => $owner,
            'counts' => ['total' => Store::count(), 'operating' => Store::whereNull('admin_suspended_at')->whereIn('status', ['active', 'grace'])->whereNotNull('expires_at')->where(function ($q) {
                $q->where('expires_at', '>', now())->orWhere(fn ($grace) => $grace->where('is_trial', false)->where('expires_at', '>', now()->subDays(7)));
            })->count(), 'suspended' => Store::whereNotNull('admin_suspended_at')->count()]]);
    }

    public function show(Request $request, Store $store): Response
    {
        $this->authorizeAdmin($request);
        $list = new ListQuery($request, ['action' => ['edit', 'expiry', 'package', 'suspend', 'resume']], 5);
        $events = $list->paginate($list->apply(StoreAdminEvent::where('store_id', $store->id)->leftJoin('users', 'users.id', '=', 'store_admin_events.admin_id')->select('store_admin_events.*', 'users.name as admin_name'), ['reason', 'users.name'], ['action' => 'action'])->orderByDesc('store_admin_events.id'));

        return Inertia::render('Admin/StoreDetail', ['store' => $this->payload($store->load(['owner', 'domain'])),
            'retention' => app(StoreRetention::class)->summary($store, true),
            'bot' => BotConnection::state($store, false)['status'] ?? 'DISCONNECTED',
            'counts' => ['staff' => StoreStaff::where('store_id', $store->id)->count(), 'products' => CatalogProduct::where('store_id', $store->id)->count(),
                'stock' => PremiumStock::where('store_id', $store->id)->where('status', 'ready')->count(), 'suppliers' => SupplierConnection::where('store_id', $store->id)->where('enabled', true)->count()],
            'events' => $events->items(), 'filters' => $list->filters, 'pagination' => ListQuery::meta($events)]);
    }

    public function destroy(Request $request, Store $store, StoreRetention $retention): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['confirmation' => 'required|string|max:100', 'preview_token' => 'required|string|size:64']);
        $lock = Cache::lock('store-retention:'.$store->id, 300);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['retention' => 'Penghapusan sedang diproses. Tunggu sebentar dan muat ulang halaman.']);
        }
        try {
            if (! hash_equals($store->fresh()->slug, $data['confirmation'])) {
                throw ValidationException::withMessages(['confirmation' => 'Ketik subdomain toko persis seperti yang ditampilkan.']);
            }
            if (! $retention->approveManualDeletion($store, $request->user('web'), $data['preview_token'])) {
                throw ValidationException::withMessages(['retention' => 'Toko belum mengajukan penghapusan atau masih memiliki kewajiban/penahan. Muat ulang dan periksa rinciannya.']);
            }
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            report($error);

            return back()->withErrors(['retention' => 'Penghapusan belum selesai. Data ditahan. Periksa bot manager/penyimpanan, lalu muat ulang pratinjau untuk mencoba kembali.']);
        } finally {
            $lock->release();
        }

        return to_route('admin.stores.index')->with('success', 'Toko telah dihapus permanen. Akun owner dan toko lainnya tetap tersimpan.');
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeAdmin($request);
        if (is_string($request->input('reason'))) {
            $request->merge(['reason' => trim($request->input('reason'))]);
        }
        $data = $request->validate(['action' => 'required|in:edit,expiry,package,suspend,resume', 'reason' => 'required|string|min:5|max:1000',
            'package' => ['required_if:action,package', 'string', Rule::in(PackageCatalog::keys())],
            'name' => 'required_if:action,edit|string|max:100', 'template' => 'required_if:action,edit|in:aurora,nebula,atlas',
            'expires_at' => 'required_if:action,expiry|date|after:now|before_or_equal:'.now()->addDays(730)->toIso8601String()]);
        DB::transaction(function () use ($request, $store, $data) {
            User::whereKey($store->owner_id)->lockForUpdate()->firstOrFail();
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'purging') {
                throw ValidationException::withMessages(['action' => 'Toko sedang dalam penghapusan dan tidak dapat diubah.']);
            }
            $fields = ['name', 'template', 'status', 'expires_at', 'category', 'plan', 'admin_suspended_at', 'admin_suspension_reason'];
            $before = $locked->only($fields);
            $before['package_name'] = PackageCatalog::name($locked->category, $locked->plan);
            switch ($data['action']) {
                case 'edit':
                    $locked->update(['name' => $data['name'], 'template' => $data['template']]);
                    break;
                case 'expiry':
                    if (! $locked->starts_at || ! $locked->expires_at || $locked->is_trial || ! in_array($locked->status, ['active', 'grace', 'expired'], true)) {
                        throw ValidationException::withMessages(['expires_at' => 'Koreksi hanya untuk langganan berbayar yang sudah pernah aktif.']);
                    }
                    if (SubscriptionInvoice::where('store_id', $locked->id)->whereIn('status', ['pending', 'paid', 'review'])->exists()) {
                        throw ValidationException::withMessages(['expires_at' => 'Selesaikan invoice atau jadwal perubahan paket terlebih dahulu.']);
                    }
                    app(StoreRetention::class)->renewed($locked);
                    $locked->update(['expires_at' => Carbon::parse($data['expires_at']), 'status' => 'active']);
                    break;
                case 'package':
                    if ($locked->is_trial || ! in_array($locked->status, ['draft', 'active', 'grace', 'expired'], true)) {
                        throw ValidationException::withMessages(['package' => 'Perubahan admin tersedia untuk toko draft atau langganan berbayar yang sudah pernah aktif. Trial harus mengikuti alur langganan.']);
                    }
                    if (SubscriptionInvoice::where('store_id', $locked->id)->whereIn('status', ['pending', 'paid', 'review'])->exists()) {
                        throw ValidationException::withMessages(['package' => 'Selesaikan invoice atau jadwal perubahan paket terlebih dahulu.']);
                    }
                    $package = collect(PackageCatalog::all())->firstWhere('key', $data['package']);
                    if ($locked->category === $package['category'] && $locked->plan === $package['plan']) {
                        throw ValidationException::withMessages(['package' => 'Pilih paket yang berbeda dari paket toko saat ini.']);
                    }
                    $subscription = $locked->subscription;
                    if ($subscription !== null) {
                        $subscription = [...$subscription, 'name' => isset($subscription['duration_days'])
                            ? PackageCatalog::offerName($package['category'], $package['plan'], (int) $subscription['duration_days']) : $package['name'],
                            'category' => $package['category'], 'plan' => $package['plan'],
                            'features' => app(SubscriptionService::class)->features($package['category'], $package['plan']), 'features_version' => 1];
                    }
                    $locked->update(['category' => $package['category'], 'plan' => $package['plan'], 'subscription' => $subscription]);
                    break;
                case 'suspend':
                    if ($locked->admin_suspended_at) {
                        throw ValidationException::withMessages(['action' => 'Toko sudah ditangguhkan.']);
                    }
                    $locked->update(['admin_suspended_at' => now(), 'admin_suspension_reason' => $data['reason']]);
                    break;
                case 'resume':
                    if (! $locked->admin_suspended_at) {
                        throw ValidationException::withMessages(['action' => 'Toko tidak sedang ditangguhkan.']);
                    }
                    $locked->update(['admin_suspended_at' => null, 'admin_suspension_reason' => null]);
                    app(SubscriptionService::class)->tick($locked);
                    $locked->refresh();
                    break;
            }
            $after = [...$locked->only($fields), 'package_name' => PackageCatalog::name($locked->category, $locked->plan)];
            $event = StoreAdminEvent::create(['store_id' => $locked->id, 'admin_id' => $request->user()->id, 'action' => $data['action'], 'reason' => $data['reason'], 'before' => $before, 'after' => $after]);
            $title = match ($data['action']) {
                'suspend' => 'Toko ditangguhkan administrator', 'resume' => 'Penangguhan toko dicabut', 'expiry' => 'Masa aktif toko dikoreksi', 'package' => 'Paket toko diubah administrator', default => 'Pengaturan toko diperbarui admin'
            };
            $message = $locked->name.': '.($data['action'] === 'package' ? $before['package_name'].' → '.$after['package_name'].'. Masa aktif tetap. ' : '').$data['reason'];
            app(PanelNotifications::class)->forStore($locked, $title, $message, $data['action'] === 'suspend' ? 'warning' : 'info', 'admin-store:'.$event->id);
        });

        return back()->with('success', 'Perubahan toko tersimpan dan tercatat. Owner serta staff aktif menerima notifikasi panel.');
    }
}
