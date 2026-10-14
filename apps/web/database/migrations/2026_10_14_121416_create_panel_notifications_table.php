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
        Schema::create('panel_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('store_staff')->cascadeOnDelete();
            $table->foreignUuid('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title', 100);
            $table->text('message');
            $table->string('level', 12)->default('info');
            $table->string('url', 500)->nullable();
            $table->string('event_key', 64)->nullable()->unique();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index(['staff_id', 'read_at', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('panel_notifications');
    }
};
