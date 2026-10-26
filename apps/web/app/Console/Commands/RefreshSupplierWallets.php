<?php

namespace App\Console\Commands;

use App\Jobs\RefreshSupplierWallet;
use App\Models\SupplierConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('digitaltoko:supplier-wallets')]
#[Description('Antrekan pemeriksaan saldo dan deposit pemasok toko operasional.')]
class RefreshSupplierWallets extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        SupplierConnection::where('enabled', true)->whereHas('store', fn ($q) => $q->whereNull('admin_suspended_at')->whereIn('status', ['active', 'grace', 'provisioning']))->orderBy('id')->chunk(100, function ($connections) {
            foreach ($connections as $connection) {
                RefreshSupplierWallet::dispatch($connection->id);
            }
        });
        $this->info('Pemeriksaan saldo pemasok masuk antrean.');

        return self::SUCCESS;
    }
}
