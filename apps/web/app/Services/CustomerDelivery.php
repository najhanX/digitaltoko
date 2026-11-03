<?php

namespace App\Services;

use App\Mail\StoreMessageMail;
use App\Models\CustomerNotice;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CustomerDelivery
{
    public function send(CustomerNotice $notice): void
    {
        Cache::lock('customer-notice:'.$notice->id, 60)->get(function () use ($notice) {
            $notice = CustomerNotice::find($notice->id);
            if (! $notice) {
                return;
            }
            DB::transaction(function () use ($notice) {
                $store = Store::whereKey($notice->store_id)->lockForUpdate()->first();
                $notice = CustomerNotice::find($notice->id);
                if (! $notice) {
                    return;
                }
                if (! $store || $store->purge_started_at) {
                    return;
                }
                $platformRetention = str_starts_with($notice->event, 'retention:') && ! $store->canConnectBot()
                    && ! in_array(config('mail.default'), ['log', 'array'], true) && filled(config('mail.from.address'));
                if ($notice->email && ! $notice->email_sent_at && ($platformRetention || app(StoreMail::class)->ready($store))) {
                    try {
                        if ($platformRetention) {
                            Mail::to($notice->email)->send((new StoreMessageMail($store->name, $notice->message))->forStore($store));
                        } else {
                            app(StoreMail::class)->send($store, $notice->email, (new StoreMessageMail($store->name, $notice->message))->forStore($store));
                        }
                        $notice->update(['email_sent_at' => now()]);
                    } catch (\Throwable) {
                    }
                }
            });
        });
    }
}
