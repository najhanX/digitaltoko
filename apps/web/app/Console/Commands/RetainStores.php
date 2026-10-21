<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\StoreRetention;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RetainStores extends Command
{
    protected $signature = 'digitaltoko:retention {--store= : UUID satu toko} {--preview : Pratinjau tanpa mengubah data atau mengirim notifikasi} {--apply : Hapus toko yang layak; memerlukan STORE_PURGE_ENABLED=true}';

    protected $description = 'Evaluasi retensi per toko dan tahan penghapusan selama kewajiban belum selesai.';

    public function handle(StoreRetention $retention, SubscriptionService $subscriptions): int
    {
        $id = $this->option('store');
        if (($id && ! Str::isUuid($id)) || ($this->option('apply') && $this->option('preview'))) {
            $this->error('Gunakan UUID toko yang valid dan pilih --preview atau --apply.');

            return self::FAILURE;
        }
        if ($this->option('apply') && ! config('platform.store_purge_enabled')) {
            $this->error('Penghapusan dinonaktifkan. Periksa --preview sebelum mengatur STORE_PURGE_ENABLED=true.');

            return self::FAILURE;
        }
        $failed = false;
        $query = Store::when($id, fn ($q) => $q->whereKey($id))->when(! $id, fn ($q) => $q->where(fn ($expired) => $expired->where('expires_at', '<=', now())->orWhere('status', 'suspended_manual')->orWhereNotNull('purge_started_at')));
        if ($id && ! $query->exists()) {
            $this->error('Toko tidak ditemukan.');

            return self::FAILURE;
        }
        $query->orderBy('id')->lazyById(100)->each(function ($store) use ($retention, $subscriptions, &$failed) {
            $lock = Cache::lock('store-retention:'.$store->id, 300);
            if (! $lock->get()) {
                $this->warn($store->id.' sedang diproses.');

                return;
            }
            try {
                if (! $this->option('preview')) {
                    $subscriptions->tick($store);
                }
                $summary = $this->option('preview') ? $retention->summary($store, true) : $retention->review($store->fresh());
                $this->line($store->id.' '.$store->name.' | '.$summary['state'].' | '.($summary['review_at'] ?? 'belum ada tanggal').' | '.implode('; ', $summary['reasons']));
                if ($this->option('preview')) {
                    $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                if ($this->option('apply') && in_array($summary['state'], ['ready', 'purging', 'error'], true)) {
                    $this->line($retention->purge($store) ? 'Data toko dihapus.' : 'Penghapusan dibatalkan karena kondisi toko berubah.');
                }
            } catch (\Throwable $error) {
                report($error);
                $this->error($store->id.' gagal diproses. Data ditahan; periksa log server.');
                $failed = true;
            } finally {
                $lock->release();
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
