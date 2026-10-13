<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('owner');
            $table->string('hanshop_user_id')->nullable()->unique();
            $table->string('phone')->nullable()->unique();
        });
        Schema::create('stores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category')->default('bot');
            $table->string('plan')->default('galaxy');
            $table->string('status')->default('draft');
            $table->boolean('is_trial')->default(false);
            $table->string('template')->default('minimal');
            $table->json('branding')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('worker_token_hash', 64)->nullable()->unique();
            $table->timestamps();
        });
        Schema::create('store_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('hostname')->unique();
            $table->string('status')->default('pending_verification');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('store_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('username');
            $table->string('email');
            $table->string('password');
            $table->json('permissions')->nullable();
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->unique(['store_id', 'username']);
            $table->unique(['store_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_staff');
        Schema::dropIfExists('store_domains');
        Schema::dropIfExists('stores');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'hanshop_user_id', 'phone']));
    }
};
