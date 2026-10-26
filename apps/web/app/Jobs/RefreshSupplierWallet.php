<?php

namespace App\Jobs;

use App\Models\SupplierConnection;
use App\Models\SupplierDeposit;
use App\Services\SupplierWallets;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class RefreshSupplierWallet implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $uniqueFor = 240;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public string $connectionId) {}

    public function uniqueId(): string
    {
        return $this->connectionId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $connection = SupplierConnection::with('store.owner')->find($this->connectionId);
        if (! $connection || ! $connection->enabled || ! $connection->store || $connection->store->admin_suspended_at || (! $connection->store->acceptsOrders() && $connection->store->status !== 'provisioning')) {
            return;
        }
        $wallets = app(SupplierWallets::class);
        $lock = Cache::lock('supplier-wallet:'.$wallets->identity($connection), 180);
        if (! $lock->get()) {
            return;
        }
        try {
            if ($connection->kind === 'hanshop' && ! $connection->store->sandbox) {
                SupplierDeposit::where('connection_id', $connection->id)->where('is_sandbox', false)->whereIn('status', ['requesting', 'unknown', 'pending'])->oldest('checked_at')->limit(3)->get()->each(function ($deposit) use ($wallets, $connection) {
                    try {
                        $wallets->checkDeposit($deposit, $connection);
                    } catch (ValidationException) { /* Keep uncertain deposits for the next check. */
                    }
                });
            }
            try {
                $wallets->refresh($connection);
            } catch (ValidationException) { /* Persisted diagnostics are shown in the panel. */
            }
        } finally {
            $lock->release();
        }
    }
}
