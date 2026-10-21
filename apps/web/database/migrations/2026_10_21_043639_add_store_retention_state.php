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
        Schema::table('stores', function (Blueprint $table) {
            $table->string('retention_state', 20)->nullable()->index();
            $table->json('retention_reasons')->nullable();
            $table->timestamp('retention_checked_at')->nullable();
            $table->timestamp('purge_started_at')->nullable();
            $table->string('retention_error')->nullable();
        });
        Schema::table('discount_vouchers', function (Blueprint $table) {
            $table->unsignedBigInteger('archived_uses')->default(0);
            $table->unsignedBigInteger('archived_sandbox_uses')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['retention_state', 'retention_reasons', 'retention_checked_at', 'purge_started_at', 'retention_error']));
        Schema::table('discount_vouchers', fn (Blueprint $table) => $table->dropColumn(['archived_uses', 'archived_sandbox_uses']));
    }
};
