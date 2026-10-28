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
        Schema::table('bot_qris_messages', function (Blueprint $table) {
            $table->string('kind', 10)->default('qris');
            $table->string('revision', 40)->nullable();
            $table->string('order_status', 30)->nullable();
            $table->string('payment_status', 30)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_qris_messages', function (Blueprint $table) {
            $table->dropColumn(['kind', 'revision', 'order_status', 'payment_status']);
        });
    }
};
