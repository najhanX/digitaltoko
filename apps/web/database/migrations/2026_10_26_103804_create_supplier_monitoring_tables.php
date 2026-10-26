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
        Schema::create('supplier_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->string('identity_key', 64)->unique();
            $table->bigInteger('balance')->nullable();
            $table->timestamp('observed_at', 6)->nullable();
            $table->timestamp('received_at', 6)->nullable();
            $table->string('source', 30)->nullable();
            $table->boolean('needs_check')->default(false);
            $table->timestamp('checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        Schema::create('supplier_deposits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('supplier_connections')->cascadeOnDelete();
            $table->string('identity_key', 64);
            $table->uuid('request_id');
            $table->string('supplier_name', 160);
            $table->string('kind', 20);
            $table->boolean('is_sandbox');
            $table->unsignedBigInteger('amount');
            $table->string('status', 30)->default('requesting');
            $table->text('details')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'request_id']);
            $table->index(['store_id', 'is_sandbox', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_deposits');
        Schema::dropIfExists('supplier_accounts');
    }
};
