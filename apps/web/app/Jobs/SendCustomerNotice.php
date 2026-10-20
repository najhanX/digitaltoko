<?php

namespace App\Jobs;

use App\Models\CustomerNotice;
use App\Services\BotManager;
use App\Services\CustomerDelivery;
use App\Support\BotConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendCustomerNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $noticeId) {}

    public function backoff(): array
    {
        return [2, 5, 15];
    }

    public function handle(CustomerDelivery $delivery, BotManager $manager): void
    {
        $notice = CustomerNotice::with('store.owner')->find($this->noticeId);
        if (! $notice) {
            return;
        }
        $store = $notice->store;
        $delivery->send($notice);
        if (! $notice->wa_sent_at && $store->canConnectBot() && BotConnection::state($store, false)['status'] === 'CONNECTED' && $manager->configured()) {
            $manager->command($store, 'notifications');
        }
    }
}
