<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('plan_options')->where(fn ($q) => $q->where('category', '!=', 'full')->orWhere('plan', '!=', 'galaxy'))->update(['active' => false]);
            $offers = DB::table('plan_options')->where('category', 'full')->where('plan', 'galaxy')->orderByDesc('active')->orderBy('id')->get();
            foreach ($offers as $offer) {
                if (preg_match('/\b(Orbit|Space|Galaxy|Jupiter|Saturn|Andromeda)\b|Web\s*\+\s*Bot/i', $offer->name)) {
                    DB::table('plan_options')->where('id', $offer->id)->update(['name' => 'Web Topup + Bot WhatsApp · '.$offer->duration_days.' hari']);
                }
            }
            DB::table('stores')->lockForUpdate()->orderBy('id')->chunkById(100, function ($stores) {
                foreach ($stores as $store) {
                    $subscription = $store->subscription ? json_decode($store->subscription, true, 512, JSON_THROW_ON_ERROR) : null;
                    if (is_array($subscription)) {
                        if ($store->category !== 'full') {
                            $subscription['legacy_package'] ??= ['category' => $store->category, 'plan' => $store->plan, 'name' => $subscription['name'] ?? null];
                        }
                        $subscription = [...$subscription, 'category' => 'full', 'plan' => 'galaxy', 'features' => ['bot', 'web', 'digiflazz', 'stock', 'hanshop', 'otomax'], 'features_version' => 3];
                    }
                    $updates = ['category' => 'full', 'plan' => 'galaxy', 'subscription' => $subscription === null ? null : json_encode($subscription, JSON_THROW_ON_ERROR)];
                    if ($store->category === 'web' && $store->status === 'provisioning') {
                        $updates['ready_at'] = null;
                    }
                    DB::table('stores')->where('id', $store->id)->update($updates);
                }
            });
            $allPlans = DB::table('plan_options')->get()->keyBy('id');
            DB::table('discount_vouchers')->where('scope_key', 'platform')->orderBy('id')->chunkById(100, function ($vouchers) use ($allPlans, $offers) {
                foreach ($vouchers as $voucher) {
                    $targets = json_decode($voucher->targets, true, 512, JSON_THROW_ON_ERROR);
                    $services = $targets['services'] ?? [];
                    if (array_intersect($services, ['bot', 'web'])) {
                        $services = [...array_diff($services, ['bot', 'web']), 'full'];
                    }
                    $targets['services'] = array_values(array_unique($services));
                    $targets['plans'] = array_values(array_unique(array_filter(array_map(function ($id) use ($allPlans, $offers) {
                        $plan = $allPlans->get($id);
                        if (! $plan) {
                            return null;
                        }
                        if ($plan->category === 'full' && $plan->plan === 'galaxy') {
                            return (string) $plan->id;
                        }
                        $replacement = $offers->firstWhere('duration_days', $plan->duration_days);

                        return $replacement ? (string) $replacement->id : null;
                    }, $targets['plans'] ?? []))));
                    $updates = ['targets' => json_encode($targets, JSON_THROW_ON_ERROR)];
                    if (! $voucher->all_targets && ! array_filter($targets)) {
                        $updates['active'] = false;
                    }
                    DB::table('discount_vouchers')->where('id', $voucher->id)->update($updates);
                }
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore the pre-migration backup to reverse commercial entitlements; invoice snapshots remain untouched.
    }
};
