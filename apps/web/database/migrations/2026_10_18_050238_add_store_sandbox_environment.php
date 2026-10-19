<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->boolean('sandbox')->default(false));
        Schema::table('customers', fn (Blueprint $table) => $table->bigInteger('sandbox_balance')->default(0));
        foreach (['customer_orders', 'customer_ledgers'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->boolean('is_sandbox')->default(false)->index());
        }
    }

    public function down(): void
    {
        foreach (['customer_orders', 'customer_ledgers'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['is_sandbox']);
                $table->dropColumn('is_sandbox');
            });
        }
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('sandbox_balance'));
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('sandbox'));
    }
};
