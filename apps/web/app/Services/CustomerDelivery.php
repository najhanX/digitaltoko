<?php

namespace App\Services;

use App\Mail\StoreMessageMail;
use App\Models\CustomerNotice;
use Illuminate\Support\Facades\Cache;

class CustomerDelivery
{
    public function send(CustomerNotice $notice): void
    {
        Cache::lock('customer-notice:'.$notice->id, 60)->get(function () use ($notice) {
            $notice->refresh();
            $store = $notice->store;
            if ($notice->email && ! $notice->email_sent_at && app(StoreMail::class)->ready($store)) {
                try {
                    app(StoreMail::class)->send($store, $notice->email, new StoreMessageMail($store->name, $notice->message));
                    $notice->update(['email_sent_at' => now()]);
                } catch (\Throwable) {
                }
            }
        });
    }
}
