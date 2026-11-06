<?php

namespace App\Console\Commands;

use App\Models\CustomerOrder;
use App\Models\Store;
use App\Models\StorePaymentSetting;
use App\Services\SharedQris;
use App\Support\HanshopIdentity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('digitaltoko:qris-shared-prepare {--apply : Hubungkan profil dan reservasi invoice lama sesudah pemeriksaan}')]
#[Description('Periksa kesiapan QRIS bersama tanpa membatalkan invoice atau mengubah saldo')]
class PrepareSharedQris extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $remote = app(HanshopIdentity::class)->call('qris/config', []);
        if (! ($remote['enabled'] ?? false) || ! preg_match('/^[a-f0-9]{64}$/', $remote['merchant'] ?? '') || ! preg_match('/^HD[0-9]+$/', $remote['owner'] ?? '')) {
            $this->error('API Hanshop belum siap. Terapkan migrasi shared_qris, lalu atur SHARED_QRIS_ENABLED, SHARED_QRIS_HANSHOP_MERCHANT dan SHARED_QRIS_HANSHOP_OWNER.');

            return self::FAILURE;
        }
        $this->line('Identitas QRIS Hanshop: '.$remote['merchant'].' · akun '.$remote['owner']);
        $pending = CustomerOrder::where('gateway', 'custom')->where('payment_status', 'unpaid')->where('status', 'awaiting_payment')->where('expires_at', '>', now())->get();
        $this->line($pending->count().' invoice berjalan perlu diperiksa.');
        if (! $this->option('apply')) {
            $this->info('Pemeriksaan saja. Hentikan sementara pembuatan invoice baru pada kedua aplikasi, lalu jalankan kembali dengan --apply.');

            return self::SUCCESS;
        }
        foreach (StorePaymentSetting::whereNotNull('custom_licensed_at')->get() as $setting) {
            if (! filled($setting->credentials['qris_payload'] ?? null)) {
                continue;
            }
            $store = Store::findOrFail($setting->store_id);
            $setting->update(['credentials' => app(SharedQris::class)->connect($store, $setting->credentials, $setting->credentials['qris_profile_id'] ?? null)]);
        }
        foreach ($pending as $order) {
            $reservation = app(HanshopIdentity::class)->call('qris/reserve', ['reference' => $order->id, 'store' => $order->store_id, 'owner' => $order->store->owner->hanshop_user_id, 'merchant' => $order->merchant_key, 'amount' => (int) $order->amount, 'existing_total' => (int) $order->total, 'created_at' => $order->created_at->toIso8601String(), 'expires_at' => $order->expires_at->toIso8601String()]);
            if (($reservation['total'] ?? null) !== $order->total) {
                $this->error('Reservasi tidak sesuai untuk '.$order->number.'. Jangan aktifkan broker sebelum ditangani.');

                return self::FAILURE;
            }
            $credentials = $order->payment_credentials ?? [];
            $profile = app(StorePaymentSetting::class)->findOrFail($order->store_id)->credentials['qris_profile_id'];
            $order->update(['payment_credentials' => [...$credentials, 'shared_reservation' => $order->id, 'qris_profile_id' => $profile]]);
        }
        $this->info('Profil dan reservasi siap. Aktifkan HANSDEV_SHARED_QRIS_BROKER=true, muat ulang konfigurasi/worker, lalu ganti koneksi HP ke endpoint profil. Jangan menghapus antrean HP.');

        return self::SUCCESS;
    }
}
