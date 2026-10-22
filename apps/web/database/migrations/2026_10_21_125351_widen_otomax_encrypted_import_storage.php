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
        Schema::table('otomax_profiles', function (Blueprint $table) {
            $table->longText('config')->change();
        });
        Schema::table('otomax_imports', function (Blueprint $table) {
            $table->longText('payload')->change();
        });
        Schema::table('order_attempts', function (Blueprint $table) {
            $table->longText('request_snapshot')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Retain the wider columns on rollback to avoid truncating encrypted data.
    }
};
