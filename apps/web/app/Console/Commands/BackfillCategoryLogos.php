<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\ImportedCategoryImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackfillCategoryLogos extends Command
{
    protected $signature = 'digitaltoko:category-logos {--store= : ID atau slug toko} {--apply : Simpan logo; tanpa opsi ini hanya pratinjau}';

    protected $description = 'Lengkapi logo kategori impor lama yang kosong, tanpa menimpa pilihan gambar owner.';

    public function handle(ImportedCategoryImage $images): int
    {
        $query = Store::query();
        if ($id = $this->option('store')) {
            $query->where(fn ($query) => $query->where('id', $id)->orWhere('slug', $id));
        }
        if (! $query->exists()) {
            $this->error('Toko tidak ditemukan.');

            return self::FAILURE;
        }
        if ($this->option('apply')) {
            $backup = 'backups/category-logos-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
            Storage::disk('local')->put($backup, $query->get(['id', 'branding'])->toJson(JSON_PRETTY_PRINT));
            $this->info('Cadangan branding: '.Storage::disk('local')->path($backup));
        }
        $count = 0;
        $query->orderBy('id')->chunkById(100, function ($stores) use ($images, &$count) {
            foreach ($stores as $store) {
                $count += $images->backfill($store, (bool) $this->option('apply'));
            }
        });
        $this->info($count.' kategori '.($this->option('apply') ? 'dilengkapi logo.' : 'dapat dilengkapi logo. Gunakan --apply untuk menyimpan.'));

        return self::SUCCESS;
    }
}
