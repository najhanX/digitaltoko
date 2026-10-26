<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_domains', function (Blueprint $table) {
            $table->string('verification_token', 64)->nullable();
            $table->timestamp('verification_expires_at')->nullable();
            $table->timestamp('dns_verified_at')->nullable();
            $table->timestamp('tls_verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('store_domains', function (Blueprint $table) {
            $table->dropColumn(['verification_token', 'verification_expires_at', 'dns_verified_at', 'tls_verified_at', 'last_checked_at', 'last_error']);
        });
    }
};
