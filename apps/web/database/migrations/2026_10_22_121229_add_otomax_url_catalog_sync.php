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
        Schema::table('supplier_connections', function (Blueprint $table) {
            $table->longText('catalog_config')->nullable();
            $table->unsignedInteger('catalog_revision')->default(0);
            $table->string('catalog_status', 20)->nullable();
            $table->string('catalog_error', 250)->nullable();
            $table->timestamp('catalog_next_sync_at')->nullable()->index();
            $table->timestamp('catalog_last_checked_at')->nullable();
        });
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->string('availability_reason', 150)->nullable();
            $table->timestamp('price_observed_at')->nullable();
        });
        Schema::table('otomax_imports', function (Blueprint $table) {
            $table->string('source', 10)->default('file')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('otomax_imports', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::table('supplier_products', fn (Blueprint $table) => $table->dropColumn(['availability_reason', 'price_observed_at']));
        Schema::table('supplier_connections', function (Blueprint $table) {
            $table->dropIndex(['catalog_next_sync_at']);
            $table->dropColumn(['catalog_config', 'catalog_revision', 'catalog_status', 'catalog_error', 'catalog_next_sync_at', 'catalog_last_checked_at']);
        });
    }
};
