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
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->json('billing')->nullable();
        });
        Schema::table('product_mappings', function (Blueprint $table) {
            $table->json('postpaid')->nullable();
        });
        Schema::table('customer_inquiries', function (Blueprint $table) {
            $table->string('status', 16)->default('ready')->index();
            $table->foreignUuid('connection_id')->nullable()->constrained('supplier_connections')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_inquiries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('connection_id');
            $table->dropColumn('status');
        });
        Schema::table('product_mappings', fn (Blueprint $table) => $table->dropColumn('postpaid'));
        Schema::table('supplier_products', fn (Blueprint $table) => $table->dropColumn('billing'));
    }
};
