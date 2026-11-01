<?php

namespace App\Services;

use App\Models\PanelNotification;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PanelNotifications
{
    public function recipient(Request $request): User|StoreStaff|null
    {
        $user = $request->user('web');
        $user = $user && in_array($user->role, ['owner', 'admin'], true) ? $user : null;
        $staff = $request->user('staff');
        $staff = $staff?->active ? $staff : null;
        if ($request->is('panel/notifications*')) {
            return $request->input('guard') === 'staff' ? $staff : ($user ?? $staff);
        }
        $store = $request->route('store');
        if (! $request->is('admin*') && $staff && ($request->is('staff/*') || ($store instanceof Store && $staff->store_id === $store->id && ! ($user?->role === 'owner' && $user->id === $store->owner_id)))) {
            return $staff;
        }

        return $user;
    }

    public function key(User|StoreStaff $recipient): string
    {
        return ($recipient instanceof StoreStaff ? 'staff:' : 'user:').$recipient->id;
    }

    public function query(User|StoreStaff $recipient): Builder
    {
        return $recipient instanceof StoreStaff
            ? PanelNotification::where('staff_id', $recipient->id)->whereNull('user_id')->where('store_id', $recipient->store_id)
            : PanelNotification::where('user_id', $recipient->id)->whereNull('staff_id');
    }

    public function summary(Request $request): ?array
    {
        if (! $request->is('owner*', 'staff/*', 'stores/*', 'admin*') || $request->is('admin/login', 'staff/*/login')) {
            return null;
        }
        $recipient = $this->recipient($request);
        if (! $recipient) {
            return null;
        }

        return ['recipient' => $this->key($recipient), 'guard' => $recipient instanceof StoreStaff ? 'staff' : 'web',
            'unread_count' => $this->query($recipient)->whereNull('read_at')->count(),
            'latest_id' => $this->query($recipient)->latest()->orderByDesc('id')->value('id')];
    }

    public function send(User|StoreStaff $recipient, string $title, string $message, string $level = 'info', ?string $url = null, ?Store $store = null, ?string $event = null): PanelNotification
    {
        if ($store && (($recipient instanceof StoreStaff && $recipient->store_id !== $store->id)
            || ($recipient instanceof User && $recipient->role === 'owner' && $recipient->id !== $store->owner_id))) {
            throw new \InvalidArgumentException('Recipient does not belong to the store.');
        }
        $data = ['user_id' => $recipient instanceof User ? $recipient->id : null,
            'staff_id' => $recipient instanceof StoreStaff ? $recipient->id : null,
            'store_id' => $recipient instanceof StoreStaff ? $recipient->store_id : $store?->id,
            'title' => $title, 'message' => $message, 'level' => $level,
            'url' => $url && preg_match('#^/(owner|staff|stores|admin)(/|$)#', $url) && ! str_contains($url, '\\') ? $url : null];
        if ($event !== null) {
            return PanelNotification::firstOrCreate(['event_key' => hash('sha256', $this->key($recipient).':'.$event)], $data);
        }

        return PanelNotification::create($data);
    }

    public function forStore(Store $store, string $title, string $message, string $level, string $event, ?string $permission = null): void
    {
        $url = '/stores/'.$store->id.'/'.(str_starts_with($event, 'order:') ? 'orders' : (str_starts_with($event, 'bot:') ? 'bot' : 'subscription'));
        $this->send($store->owner, $title, $message, $level, $url, $store, $event);
        foreach (StoreStaff::where('store_id', $store->id)->where('active', true)->get() as $staff) {
            if ($permission && ! in_array($permission, $staff->permissions ?? [], true)
                && ! ($permission === 'orders.view' && count(array_intersect(['orders.resend', 'guests.resolve'], $staff->permissions ?? [])) > 0)
                && ! ($permission === 'bot.view' && in_array('bot.manage', $staff->permissions ?? [], true))) {
                continue;
            }
            $this->send($staff, $title, $message, $level, $url, $store, $event);
        }
    }

    public function captureAction(Request $request, Response $response): ?array
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD') || ! $response->isRedirect()
            || ! $request->is('owner/*', 'staff/*', 'stores/*', 'admin/*') || $request->is('staff/*/login', 'admin/login')) {
            return null;
        }
        $recipient = $this->recipient($request);
        $new = $request->session()->get('_flash.new', []);
        $level = in_array('success', $new, true) ? 'success' : (in_array('error', $new, true) ? 'error' : (in_array('errors', $new, true) ? 'warning' : null));
        $message = $level ? $request->session()->get($level) : null;
        if ($level === 'warning') {
            $message = 'Data belum tersimpan. Periksa isian formulir dan coba kembali.';
            if ($request->is('stores/*/articles', 'stores/*/articles/*')) {
                $reason = $request->session()->get('errors')?->getBag('default')->first();
                if (is_string($reason) && $reason !== '') {
                    $message = mb_substr($reason, 0, 500);
                }
            }
        }
        if (! $recipient || ! is_string($message) || $message === '') {
            return null;
        }
        $location = $response->headers->get('Location');
        $path = is_string($location) ? parse_url($location, PHP_URL_PATH) : null;
        $url = is_string($path) && preg_match('#^/(owner|staff|stores|admin)(/|$)#', $path) ? $path : null;
        $store = $request->route('store');
        if (! $store instanceof Store && $recipient instanceof User && $url && preg_match('#^/stores/([a-f0-9-]{36})/#', $url, $match)) {
            $store = Store::whereKey($match[1])->where('owner_id', $recipient->id)->first();
        }
        if ($store instanceof Store && ! Store::whereKey($store->id)->exists()) {
            $store = null;
        }
        $method = $request->method();
        $title = match (true) {
            $request->is('stores/*/bot/connect') => 'Permintaan koneksi WhatsApp',
            $request->is('stores/*/bot/disconnect') => 'Koneksi WhatsApp dilepas',
            $request->is('stores/*/subscription/activate') => 'Toko diaktifkan',
            $request->is('stores/*/subscription/checkout') => 'Invoice sewa disiapkan',
            $request->is('stores/*/subscription/*/refresh') => 'Pembayaran diperiksa',
            $request->is('owner/profile/*') => 'Profil dan verifikasi',
            $request->is('admin/verifications/*') => 'Verifikasi owner diperiksa',
            $method === 'DELETE' => 'Data berhasil dihapus',
            $method === 'PATCH', $method === 'PUT' => 'Perubahan berhasil disimpan',
            default => 'Tindakan berhasil',
        };
        if ($level === 'error') {
            $title = 'Tindakan belum berhasil';
        } elseif ($level === 'warning') {
            $title = 'Periksa isian formulir';
        }
        $notification = $this->send($recipient, $title, $message, $level, $url, $store instanceof Store ? $store : null);

        return [...$this->payload($notification, $recipient), 'recipient' => $this->key($recipient)];
    }

    public function payload(PanelNotification $notification, User|StoreStaff|null $recipient = null): array
    {
        $payload = $notification->only(['id', 'title', 'message', 'level', 'url', 'store_id', 'created_at', 'read_at']);
        if ($recipient instanceof StoreStaff && $payload['url']) {
            $permissions = $recipient->permissions ?? [];
            $allowed = match ($payload['url']) {
                '/staff/'.$recipient->store_id.'/access' => true,
                '/staff/'.$recipient->store_id.'/dashboard' => in_array('dashboard.view', $permissions, true),
                '/staff/'.$recipient->store_id.'/settings' => in_array('web.manage', $permissions, true),
                '/stores/'.$recipient->store_id.'/subscription' => in_array('subscription.view', $permissions, true),
                '/stores/'.$recipient->store_id.'/products', '/stores/'.$recipient->store_id.'/catalog-structure', '/stores/'.$recipient->store_id.'/sources' => count(array_intersect(['products.view', 'products.manage', 'pricing.manage'], $permissions)) > 0,
                '/stores/'.$recipient->store_id.'/stock' => count(array_intersect(['stock.view', 'stock.manage', 'stock.credentials'], $permissions)) > 0,
                '/stores/'.$recipient->store_id.'/orders' => count(array_intersect(['orders.view', 'orders.manage', 'orders.resend', 'guests.resolve', 'payments.confirm'], $permissions)) > 0,
                '/stores/'.$recipient->store_id.'/payments' => count(array_intersect(['payments.view', 'payments.policy', 'checkout.manage'], $permissions)) > 0,
                '/stores/'.$recipient->store_id.'/bot' => in_array('bot.view', $permissions, true) || in_array('bot.manage', $permissions, true),
                default => false,
            };
            if (! $allowed) {
                $payload['url'] = null;
            }
        }

        return $payload;
    }
}
