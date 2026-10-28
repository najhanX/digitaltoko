<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_health_signals', function (Blueprint $table): void {
            $table->string('name', 190)->primary();
            $table->string('group', 24)->index();
            $table->string('label', 180);
            $table->string('status', 16)->index();
            $table->foreignUuid('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('details');
            $table->dateTime('observed_at')->nullable();
            $table->dateTime('checked_at')->index();
        });
        Schema::create('system_webhook_health', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('bucket')->index();
            $table->string('provider', 16);
            $table->unsignedSmallInteger('http_status');
            $table->string('outcome', 16);
            $table->unsignedInteger('requests')->default(0);
            $table->dateTime('last_seen_at');
            $table->unique(['bucket', 'provider', 'http_status', 'outcome'], 'system_webhook_bucket_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_webhook_health');
        Schema::dropIfExists('system_health_signals');
    }
};
