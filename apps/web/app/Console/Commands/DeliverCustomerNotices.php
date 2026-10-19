<?php

namespace App\Console\Commands;

use App\Models\CustomerNotice;
use App\Services\CustomerDelivery;
use Illuminate\Console\Command;

class DeliverCustomerNotices extends Command
{
    protected $signature = 'digitaltoko:customer-notices {--limit=100}';

    protected $description = 'Kirim ulang outbox notifikasi pelanggan tanpa menunggu rekonsiliasi transaksi.';

    public function handle(CustomerDelivery $delivery): int
    {
        $deadline = microtime(true) + 20;
        CustomerNotice::where(fn ($q) => $q->whereNull('wa_sent_at')->orWhere(fn ($email) => $email->whereNotNull('email')->whereNull('email_sent_at')))
            ->oldest('updated_at')->limit(max(1, min(500, (int) $this->option('limit'))))->get()->each(function ($notice) use ($delivery, $deadline) {
                if (microtime(true) > $deadline) {
                    return false;
                }
                $delivery->send($notice);
                $notice->touch();
            });

        return self::SUCCESS;
    }
}
