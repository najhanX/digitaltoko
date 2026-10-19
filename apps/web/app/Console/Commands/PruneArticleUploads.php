<?php

namespace App\Console\Commands;

use App\Models\ArticleAsset;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PruneArticleUploads extends Command
{
    protected $signature = 'digitaltoko:prune-article-uploads';

    protected $description = 'Bersihkan unggahan artikel yang tidak disimpan setelah 24 jam.';

    public function handle(): int
    {
        $count = 0;
        ArticleAsset::whereNull('article_id')->where('created_at', '<', now()->subDay())->orderBy('id')->chunkById(100, function ($assets) use (&$count) {
            foreach ($assets as $candidate) {
                DB::transaction(function () use ($candidate, &$count) {
                    Store::whereKey($candidate->store_id)->lockForUpdate()->first();
                    $asset = ArticleAsset::whereKey($candidate->id)->whereNull('article_id')->where('created_at', '<', now()->subDay())->lockForUpdate()->first();
                    if ($asset) {
                        $path = $asset->path;
                        $asset->delete();
                        DB::afterCommit(fn () => Storage::disk('local')->delete($path));
                        $count++;
                    }
                });
            }
        });
        $this->info($count.' unggahan artikel yang tidak digunakan dibersihkan.');

        return self::SUCCESS;
    }
}
