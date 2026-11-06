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
        Schema::create('qris_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->char('merchant_key', 64)->unique();
            $table->string('name', 150);
            $table->text('credentials');
            $table->timestamps();
        });
        Schema::create('qris_events', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->foreignUuid('profile_id')->constrained('qris_profiles')->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamp('occurred_at');
            $table->string('status', 20);
            $table->foreignUuid('order_id')->nullable()->constrained('customer_orders')->nullOnDelete();
            $table->text('receipt')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qris_events');
        Schema::dropIfExists('qris_profiles');
    }
};
