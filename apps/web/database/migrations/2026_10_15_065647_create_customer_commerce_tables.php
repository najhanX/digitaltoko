<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('phone', 20);
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->timestamp('phone_verified_at')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->bigInteger('balance')->default(0);
            $t->rememberToken();
            $t->timestamps();
            $t->unique(['store_id', 'phone']);
            $t->unique(['store_id', 'email']);
        });
        Schema::create('store_payment_settings', function (Blueprint $t) {
            $t->foreignUuid('store_id')->primary()->constrained()->cascadeOnDelete();
            $t->string('gateway')->nullable();
            $t->text('credentials')->nullable();
            $t->boolean('sandbox')->default(true);
            $t->boolean('allow_guest')->default(true);
            $t->boolean('closing')->default(false);
            $t->boolean('refund_unique')->default(false);
            $t->boolean('topup_unique')->default(false);
            $t->string('fee_markup', 100)->default('0');
            $t->string('fee_payer')->default('store');
            $t->timestamp('custom_licensed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('commerce_locks', fn (Blueprint $t) => $t->string('id', 64)->primary());
        Schema::create('customer_inquiries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $t->string('phone', 20);
            $t->string('destination', 255);
            $t->string('reference', 64)->unique();
            $t->string('request_key', 64)->unique();
            $t->string('request_hash', 64);
            $t->bigInteger('amount');
            $t->bigInteger('cost');
            $t->text('data');
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('customer_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->nullable()->constrained('catalog_products')->nullOnDelete();
            $t->foreignUuid('inquiry_id')->nullable()->unique()->constrained('customer_inquiries')->nullOnDelete();
            $t->string('number', 40)->unique();
            $t->string('idempotency_key', 128);
            $t->string('request_hash', 64);
            $t->string('channel', 10);
            $t->string('purpose', 20)->default('product');
            $t->string('status', 30)->default('awaiting_payment');
            $t->string('payment_status', 20)->default('unpaid');
            $t->string('gateway', 20);
            $t->string('phone', 20);
            $t->string('email')->nullable();
            $t->string('destination', 255);
            $t->json('snapshot');
            $t->bigInteger('amount');
            $t->bigInteger('fee')->default(0);
            $t->unsignedInteger('unique_code')->default(0);
            $t->bigInteger('total');
            $t->bigInteger('credit_amount')->default(0);
            $t->bigInteger('refund_amount')->default(0);
            $t->string('active_amount_key', 64)->nullable()->unique();
            $t->string('merchant_key', 64)->nullable()->index();
            $t->text('payment_credentials')->nullable();
            $t->string('provider_id')->nullable();
            $t->text('payment_url')->nullable();
            $t->text('qr_payload')->nullable();
            $t->text('qr_image')->nullable();
            $t->text('result')->nullable();
            $t->json('resolution')->nullable();
            $t->string('message', 500)->nullable();
            $t->timestamp('expires_at');
            foreach (['paid_at', 'completed_at', 'refunded_at', 'pending_notified_at'] as $field) {
                $t->timestamp($field)->nullable();
            }
            $t->timestamps();
            $t->unique(['store_id', 'idempotency_key']);
            $t->index(['store_id', 'status', 'created_at']);
            $t->index(['customer_id', 'created_at']);
        });
        Schema::create('order_attempts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained('customer_orders')->cascadeOnDelete();
            $t->string('reference', 64)->unique();
            $t->unsignedInteger('position');
            $t->string('source', 20);
            $t->bigInteger('cost');
            $t->string('status', 30)->default('ready');
            $t->text('request_snapshot');
            $t->string('provider_id')->nullable();
            $t->timestamp('last_checked_at')->nullable();
            $t->text('result')->nullable();
            $t->string('message', 500)->nullable();
            $t->timestamps();
            $t->unique(['order_id', 'position']);
        });
        Schema::create('customer_ledgers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $t->string('event', 160)->unique();
            $t->string('reason', 40);
            $t->json('metadata')->nullable();
            $t->bigInteger('amount');
            $t->bigInteger('balance_after');
            $t->timestamps();
            $t->index(['customer_id', 'created_at']);
        });
        Schema::create('commerce_receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained('customer_orders')->cascadeOnDelete();
            $t->string('event', 160)->unique();
            $t->bigInteger('amount');
            $t->timestamp('paid_at');
            $t->timestamps();
        });
        Schema::create('customer_notices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('order_id')->nullable()->constrained('customer_orders')->cascadeOnDelete();
            $t->string('event', 160)->unique();
            $t->string('phone', 20);
            $t->string('email')->nullable();
            $t->text('message');
            $t->timestamp('wa_sent_at')->nullable();
            $t->timestamp('email_sent_at')->nullable();
            $t->timestamps();
        });
        Schema::create('module_purchases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $t->bigInteger('amount');
            $t->string('status')->default('pending');
            $t->bigInteger('total')->nullable();
            $t->text('qr_url')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
        Schema::create('commerce_options', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->bigInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        foreach (['commerce_options', 'module_purchases', 'customer_notices', 'commerce_receipts', 'customer_ledgers', 'order_attempts', 'customer_orders', 'customer_inquiries', 'commerce_locks', 'store_payment_settings', 'customers'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
