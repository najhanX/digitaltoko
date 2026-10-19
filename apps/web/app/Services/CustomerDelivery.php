<?php

namespace App\Services;

use App\Models\CustomerNotice;
use App\Support\BotConnection;
use App\Support\HanshopIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class CustomerDelivery
{
    public function send(CustomerNotice $notice): void
    {
        Cache::lock('customer-notice:'.$notice->id, 60)->get(function () use ($notice) {
            $notice->refresh();
            $store = $notice->store;
            if (! $notice->wa_sent_at && ($store->category === 'web' || ! $store->canConnectBot() || BotConnection::state($store, false)['status'] !== 'CONNECTED')) {
                try {
                    app(HanshopIdentity::class)->call('message', ['store_id' => $store->id, 'id_user' => $store->owner->hanshop_user_id, 'access_until' => now()->addMinutes(5)->toIso8601String(), 'phone' => $notice->phone, 'message' => $notice->message]);
                    $notice->update(['wa_sent_at' => now()]);
                } catch (\Throwable) {
                }
            }
            if ($notice->email && ! $notice->email_sent_at && ! in_array(config('mail.default'), ['log', 'array'], true)) {
                try {
                    Mail::raw($notice->message, function ($mail) use ($notice, $store) {
                        $mail->to($notice->email)->subject($store->name.' · Informasi pesanan');
                    });
                    $notice->update(['email_sent_at' => now()]);
                } catch (\Throwable) {
                }
            }
        });
    }
}
