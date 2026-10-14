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
        Schema::create('supplier_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->text('credentials')->nullable();
            $table->json('catalog_modes')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('last_error', 250)->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'kind']);
        });
        Schema::create('supplier_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('supplier_connections')->cascadeOnDelete();
            $table->string('sku', 150);
            $table->string('mode', 12)->default('prepaid');
            $table->string('name', 150);
            $table->string('kind', 20)->default('ppob');
            $table->string('service', 100);
            $table->string('category', 100);
            $table->unsignedBigInteger('cost');
            $table->boolean('available')->default(false);
            $table->unsignedInteger('quantity')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->unique(['connection_id', 'mode', 'sku']);
            $table->index(['store_id', 'service', 'category']);
        });
        Schema::create('catalog_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('kind', 20)->default('ppob');
            $table->string('service', 100);
            $table->string('category', 100);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('sale_price')->default(0);
            $table->string('markup', 100)->nullable();
            $table->boolean('auto_update')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'code']);
            $table->index(['store_id', 'active', 'category']);
        });
        Schema::create('product_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignUuid('supplier_product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('source_key', 160);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'source_key']);
        });
        Schema::create('premium_stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->text('payload');
            $table->unsignedBigInteger('cost');
            $table->string('status', 12)->default('ready');
            $table->string('order_reference', 100)->nullable();
            $table->string('note', 250)->nullable();
            $table->timestamps();
            $table->index(['store_id', 'product_id', 'status']);
        });
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 12);
            $table->string('key', 100);
            $table->string('markup', 100)->nullable();
            $table->boolean('auto_update')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'scope', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('premium_stocks');
        Schema::dropIfExists('product_mappings');
        Schema::dropIfExists('catalog_products');
        Schema::dropIfExists('supplier_products');
        Schema::dropIfExists('supplier_connections');
    }
};
