<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope_key', 40);
            $table->string('code', 40);
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->boolean('all_targets')->default(true);
            $table->string('type', 10);
            $table->unsignedBigInteger('value');
            $table->unsignedBigInteger('min_purchase')->default(0);
            $table->unsignedBigInteger('max_discount')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->json('targets');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['scope_key', 'code']);
        });
        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('voucher_id')->constrained('discount_vouchers')->cascadeOnDelete();
            $table->string('subject_type', 20);
            $table->uuid('subject_id');
            $table->boolean('is_sandbox')->default(false);
            $table->unsignedBigInteger('discount');
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id']);
        });
        Schema::table('module_purchases', fn (Blueprint $table) => $table->json('snapshot')->nullable());
    }

    public function down(): void
    {
        Schema::table('module_purchases', fn (Blueprint $table) => $table->dropColumn('snapshot'));
        Schema::dropIfExists('voucher_redemptions');
        Schema::dropIfExists('discount_vouchers');
    }
};
