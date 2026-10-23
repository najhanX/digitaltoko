<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('plan_options')->where('plan', '!=', 'galaxy')->update(['active' => false]);
            foreach (['bot' => 'Bot WhatsApp', 'web' => 'Web Topup', 'full' => 'Web + Bot WhatsApp'] as $category => $name) {
                $offers = DB::table('plan_options')->where('category', $category)->where('plan', 'galaxy')->lockForUpdate()->get();
                foreach ($offers as $offer) {
                    if (preg_match('/\b(Orbit|Space|Galaxy|Jupiter|Saturn|Andromeda)\b/i', $offer->name)) {
                        DB::table('plan_options')->where('id', $offer->id)->update(['name' => $name.' · '.$offer->duration_days.' hari']);
                    }
                }
                DB::table('stores')->where('category', $category)->lockForUpdate()->orderBy('id')->chunkById(100, function ($stores) use ($category) {
                    foreach ($stores as $store) {
                        $subscription = $store->subscription ? json_decode($store->subscription, true, 512, JSON_THROW_ON_ERROR) : null;
                        if (is_array($subscription)) {
                            $subscription['plan'] = 'galaxy';
                            $subscription['features'] = array_values(array_filter([$category !== 'web' ? 'bot' : null, $category !== 'bot' ? 'web' : null, 'digiflazz', 'stock', 'hanshop', 'otomax']));
                            $subscription['features_version'] = 2;
                        }
                        DB::table('stores')->where('id', $store->id)->update(['plan' => 'galaxy', 'subscription' => $subscription === null ? null : json_encode($subscription, JSON_THROW_ON_ERROR)]);
                    }
                });
            }
        });
    }

    /** Commercial terms and historical invoices remain untouched; reversing policy requires restoring a backup. */
    public function down(): void {}
};
