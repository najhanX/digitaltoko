<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_options', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->string('plan');
            $table->unsignedInteger('price');
            $table->unsignedInteger('duration_days');
            $table->boolean('active')->default(false);
            $table->timestamps();
        });
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users');
            $table->json('snapshot');
            $table->string('purpose');
            $table->string('status')->default('pending')->index();
            $table->string('provider_id')->nullable()->unique();
            $table->unsignedInteger('total')->nullable();
            $table->text('qr_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->json('subscription')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('trial_requested_at')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trial_used_at')->nullable();
            $table->uuid('trial_store_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['trial_used_at', 'trial_store_id']));
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['subscription', 'starts_at', 'ready_at', 'trial_requested_at']));
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('plan_options');
    }
};
