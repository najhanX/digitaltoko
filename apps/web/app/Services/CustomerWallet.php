<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerWallet
{
    public function apply(Customer $customer, int $amount, string $event, string $reason, array $metadata = []): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Wallet mutation requires a database transaction.');
        }
        $locked = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
        $existing = CustomerLedger::where('event', $event)->first();
        if ($existing) {
            abort_unless($existing->customer_id === $locked->id && $existing->amount === $amount, 409);

            return;
        }
        if ($locked->balance + $amount < 0) {
            throw ValidationException::withMessages(['payment' => 'Saldo pelanggan tidak mencukupi.']);
        }
        $locked->update(['balance' => $locked->balance + $amount]);
        CustomerLedger::create(['store_id' => $locked->store_id, 'customer_id' => $locked->id, 'event' => $event, 'reason' => $reason, 'metadata' => $metadata, 'amount' => $amount, 'balance_after' => $locked->balance]);
    }
}
