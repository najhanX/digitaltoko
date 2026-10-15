<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('name_key', 100);
            $table->timestamps();
            $table->unique(['store_id', 'name_key']);
        });
        Schema::create('catalog_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('service_id')->constrained('catalog_services')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('name_key', 100);
            $table->timestamps();
            $table->unique(['service_id', 'name_key']);
            $table->index(['store_id', 'name_key']);
        });
        Schema::table('catalog_products', fn (Blueprint $table) => $table->foreignUuid('category_id')->nullable()->constrained('catalog_categories')->nullOnDelete());
        DB::table('catalog_products')->orderBy('id')->chunkById(200, function ($products) {
            foreach ($products as $product) {
                $serviceKey = ['store_id' => $product->store_id, 'name_key' => mb_strtolower(trim($product->service))];
                $service = DB::table('catalog_services')->where($serviceKey)->first();
                if (! $service) {
                    $service = (object) ['id' => (string) Str::uuid7()];
                    DB::table('catalog_services')->insert([...$serviceKey, 'id' => $service->id, 'name' => $product->service, 'created_at' => now(), 'updated_at' => now()]);
                }
                $categoryKey = ['service_id' => $service->id, 'name_key' => mb_strtolower(trim($product->category))];
                $category = DB::table('catalog_categories')->where($categoryKey)->first();
                if (! $category) {
                    $category = (object) ['id' => (string) Str::uuid7()];
                    DB::table('catalog_categories')->insert([...$categoryKey, 'store_id' => $product->store_id, 'id' => $category->id, 'name' => $product->category, 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('catalog_products')->where('id', $product->id)->update(['category_id' => $category->id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', fn (Blueprint $table) => $table->dropConstrainedForeignId('category_id'));
        Schema::dropIfExists('catalog_categories');
        Schema::dropIfExists('catalog_services');
    }
};
