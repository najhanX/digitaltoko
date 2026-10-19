<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_orders', function (Blueprint $table) {
            $table->string('guest_checkout_hash', 64)->nullable();
            $table->index(['store_id', 'guest_checkout_hash', 'status'], 'customer_orders_guest_pending');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_orders', function (Blueprint $table) {
            $table->dropIndex('customer_orders_guest_pending');
            $table->dropColumn('guest_checkout_hash');
        });
    }
};
