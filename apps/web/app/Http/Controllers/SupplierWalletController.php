<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\SupplierDeposit;
use App\Services\SupplierWallets;
use App\Support\CatalogAccess;
use App\Support\ListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierWalletController extends Controller
{
    private function access(Request $request, Store $store): string
    {
        $role = CatalogAccess::authorize($request, $store, 'suppliers.balance');
        CatalogAccess::operational($store);

        return $role;
    }

    public function index(Request $request, Store $store, SupplierWallets $wallets): Response
    {
        $role = $this->access($request, $store);
        $tab = $request->query('tab') === 'deposits' ? 'deposits' : 'accounts';
        $list = new ListQuery($request, ['kind' => ['digiflazz', 'hanshop', 'otomax']], 10);
        $canDeposit = CatalogAccess::has($request, $role, 'suppliers.deposit');
        if ($tab === 'deposits') {
            abort_unless($canDeposit, 403);
            $query = SupplierDeposit::where('store_id', $store->id)->where('is_sandbox', $store->sandbox);
            if ($role !== 'owner') {
                $query->where('kind', '!=', 'hanshop');
            }
            $page = $list->paginate($list->apply($query, ['supplier_name', 'status'], ['kind' => 'kind'])->latest()->orderBy('id'));
            $items = $page->getCollection()->map(fn ($item) => [...$item->only(['id', 'connection_id', 'supplier_name', 'kind', 'amount', 'status', 'created_at', 'checked_at', 'is_sandbox']), 'details' => $item->details]);
        } else {
            $query = SupplierConnection::with('store.owner')->where('store_id', $store->id)->whereIn('kind', ['digiflazz', 'hanshop', 'otomax']);
            if ($role !== 'owner') {
                $query->where('kind', '!=', 'hanshop');
            }
            $page = $list->paginate($list->apply($query, ['name'], ['kind' => 'kind'])->orderBy('name')->orderBy('id'));
            $items = $page->getCollection()->map(function ($connection) use ($wallets, $canDeposit, $role) {
                $account = $wallets->account($connection);

                return [...$connection->only(['id', 'kind', 'name', 'enabled', 'verified_at', 'synced_at']),
                    'name' => $connection->name ?: ucfirst($connection->kind),
                    'minimum_deposit' => $wallets->minimumDeposit($connection),
                    'account' => $connection->kind === 'hanshop' ? $connection->store->owner->hanshop_user_id : ($connection->credentials[$connection->kind === 'otomax' ? 'member_id' : 'username'] ?? ''),
                    'wallet' => $account->only(['balance', 'observed_at', 'received_at', 'source', 'checked_at', 'last_error', 'needs_check']),
                    'can_deposit' => $canDeposit && ($connection->kind !== 'hanshop' || $role === 'owner')];
            });
        }

        return Inertia::render('Owner/SupplierWallets', ['role' => $role, 'store' => $store->only(['id', 'name', 'status', 'sandbox']), 'tab' => $tab, 'items' => $items, 'filters' => $list->filters, 'pagination' => ListQuery::meta($page), 'canDeposit' => $canDeposit]);
    }

    public function refresh(Request $request, Store $store, SupplierConnection $connection, SupplierWallets $wallets): RedirectResponse
    {
        $role = $this->access($request, $store);
        abort_unless($connection->store_id === $store->id && $connection->enabled, 404);
        abort_if($connection->kind === 'hanshop' && $role !== 'owner', 403);
        $wallets->refresh($connection);

        return back()->with('success', 'Pemeriksaan saldo selesai. Cache akun pemasok digunakan bila masih segar.');
    }

    public function deposit(Request $request, Store $store, SupplierConnection $connection, SupplierWallets $wallets): RedirectResponse
    {
        $role = $this->access($request, $store);
        CatalogAccess::authorize($request, $store, 'suppliers.deposit');
        abort_unless($connection->store_id === $store->id && $connection->enabled, 404);
        abort_if($connection->kind === 'hanshop' && $role !== 'owner', 403);
        $data = $request->validate(['amount' => 'required|integer|min:'.$wallets->minimumDeposit($connection).'|max:100000000', 'request_id' => 'required|uuid',
            'bank' => [Rule::requiredIf($connection->kind === 'digiflazz'), 'nullable', Rule::in(['BCA', 'MANDIRI', 'BRI', 'BNI', 'Flip', 'ShopeePay'])],
            'owner_name' => [Rule::requiredIf($connection->kind === 'digiflazz'), 'nullable', 'string', 'max:100']], ['amount.min' => 'Minimal deposit '.ucfirst($connection->kind).' adalah Rp'.number_format($wallets->minimumDeposit($connection), 0, ',', '.').'.']);
        $record = $wallets->deposit($connection, $data);
        if ($record->status === 'failed') {
            return to_route('supplier-wallets.index', ['store' => $store->id, 'tab' => 'deposits'])->with('error', $record->details['message'] ?? 'Permintaan deposit ditolak pemasok. Buka rincian permintaan.');
        }

        return to_route('supplier-wallets.index', ['store' => $store->id, 'tab' => 'deposits'])->with('success', $record->status === 'unknown' ? 'Hasil belum pasti. Buka rincian permintaan sebelum membuat tiket baru.' : 'Permintaan deposit tersimpan. Buka rincian untuk instruksi pembayaran.');
    }

    public function check(Request $request, Store $store, SupplierDeposit $deposit, SupplierWallets $wallets): RedirectResponse
    {
        $role = $this->access($request, $store);
        CatalogAccess::authorize($request, $store, 'suppliers.deposit');
        abort_unless($deposit->store_id === $store->id && $deposit->is_sandbox === $store->sandbox, 404);
        abort_if($deposit->kind === 'hanshop' && $role !== 'owner', 403);
        $connection = SupplierConnection::where('store_id', $store->id)->findOrFail($deposit->connection_id);
        $wallets->checkDeposit($deposit, $connection);

        return back()->with('success', 'Status deposit diperiksa tanpa membuat permintaan baru.');
    }
}
