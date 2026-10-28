<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_qris_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('customer_orders')->cascadeOnDelete();
            $table->char('key_hash', 64);
            $table->text('message_key');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'key_hash']);
            $table->index(['store_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_qris_messages');
    }
};
