<?php

namespace App\Support;

use App\Models\Store;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;

class CatalogAccess
{
    public static function authorize(Request $request, Store $store, string $permission = 'products.view'): string
    {
        $owner = $request->user('web');
        if ($owner?->role === 'owner' && $owner->id === $store->owner_id) {
            abort_unless($owner->verification_status === 'approved', 403);

            return 'owner';
        }
        $staff = $request->user('staff');
        $grants = $staff?->permissions ?? [];
        $implied = match ($permission) {
            'vouchers.view' => ['vouchers.manage'], 'articles.view' => ['articles.manage'], 'orders.view' => ['orders.manage', 'orders.resend', 'guests.resolve'], 'customers.view' => ['customers.contacts', 'wallet.view', 'wallet.settlement'], 'payments.view' => ['payments.policy', 'checkout.manage'], 'products.view' => ['products.manage', 'pricing.manage'],'stock.view' => ['stock.manage', 'stock.credentials'],default => []
        };
        abort_unless($staff && $staff->active && $staff->store_id === $store->id && (in_array($permission, $grants, true) || count(array_intersect($implied, $grants)) > 0), 403);

        return 'staff';
    }

    public static function has(Request $request, string $role, string $permission): bool
    {
        return $role === 'owner' || in_array($permission, $request->user('staff')?->permissions ?? [], true);
    }

    public static function operational(Store $store): void
    {
        abort_unless(! $store->admin_suspended_at && ($store->status === 'provisioning' || $store->acceptsOrders()), 403, 'Aktifkan atau perpanjang paket toko terlebih dahulu. Jika toko ditangguhkan, hubungi administrator.');
    }

    public static function supports(Store $store, string $source): bool
    {
        $snapshot = $store->subscription ?? [];
        $features = $snapshot['features'] ?? app(SubscriptionService::class)->features($snapshot['category'] ?? $store->category, $snapshot['plan'] ?? $store->plan);

        return in_array($source, $features, true);
    }
}
