<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discount_vouchers', function (Blueprint $table) {
            $table->boolean('once_per_user')->default(false);
        });
        Schema::table('voucher_redemptions', function (Blueprint $table) {
            $table->string('claimant_type', 16)->nullable();
            $table->string('claimant_id', 64)->nullable();
            $table->index(['voucher_id', 'is_sandbox', 'claimant_type', 'claimant_id'], 'voucher_claimant_index');
        });
        DB::table('voucher_redemptions')->orderBy('id')->chunkById(300, function ($rows) {
            foreach ($rows as $row) {
                $id = match ($row->subject_type) {
                    'order' => DB::table('customer_orders')->where('id', $row->subject_id)->value('customer_id'),
                    'subscription' => DB::table('subscription_invoices')->where('id', $row->subject_id)->value('owner_id'),
                    'module' => DB::table('module_purchases')->join('stores', 'stores.id', '=', 'module_purchases.store_id')->where('module_purchases.id', $row->subject_id)->value('stores.owner_id'),
                    default => null,
                };
                if ($id !== null) {
                    DB::table('voucher_redemptions')->where('id', $row->id)->update(['claimant_type' => $row->subject_type === 'order' ? 'customer' : 'owner', 'claimant_id' => (string) $id]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('voucher_redemptions', function (Blueprint $table) {
            $table->dropIndex('voucher_claimant_index');
            $table->dropColumn(['claimant_type', 'claimant_id']);
        });
        Schema::table('discount_vouchers', fn (Blueprint $table) => $table->dropColumn('once_per_user'));
    }
};
