<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->string('title', 180);
            $table->string('slug', 200);
            $table->string('author_name', 100);
            $table->text('excerpt')->nullable();
            $table->string('cover')->nullable();
            $table->json('content');
            $table->json('tags');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'slug']);
            $table->index(['store_id', 'status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_articles');
    }
};
