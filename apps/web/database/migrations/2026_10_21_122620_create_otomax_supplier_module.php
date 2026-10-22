<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('otomax_profiles')) {
            Schema::create('otomax_profiles', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->foreignUuid('store_id')->constrained()->cascadeOnDelete();
                $t->string('type', 12);
                $t->string('name', 100);
                $t->unsignedInteger('version')->default(1);
                $t->text('config');
                $t->timestamps();
                $t->index(['store_id', 'type']);
            });
        }
        if (! Schema::hasIndex('supplier_connections', 'supplier_connections_store_id_index')) {
            Schema::table('supplier_connections', function (Blueprint $t) {
                $t->index('store_id');
            });
        }
        Schema::table('supplier_connections', function (Blueprint $t) {
            $t->dropUnique(['store_id', 'kind']);
            $t->string('slot', 40)->default('primary');
            $t->string('name', 100)->nullable();
            $t->json('settings')->nullable();
            $t->foreignUuid('response_profile_id')->nullable()->constrained('otomax_profiles')->nullOnDelete();
            $t->unique(['store_id', 'kind', 'slot']);
        });
        Schema::create('otomax_imports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('connection_id')->constrained('supplier_connections')->cascadeOnDelete();
            $t->string('status', 20)->default('preview');
            $t->string('filename', 200);
            $t->string('file_hash', 64);
            $t->unsignedInteger('processed')->default(0);
            $t->text('payload');
            $t->json('summary');
            $t->string('error', 250)->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('otomax_tickets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('connection_id')->constrained('supplier_connections')->cascadeOnDelete();
            $t->uuid('request_id');
            $t->unsignedBigInteger('amount');
            $t->string('status', 20)->default('requesting');
            $t->boolean('is_sandbox');
            $t->text('result')->nullable();
            $t->timestamps();
            $t->unique(['connection_id', 'request_id']);
        });
        Schema::create('otomax_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('connection_id')->constrained('supplier_connections')->cascadeOnDelete();
            $t->string('operation', 20);
            $t->string('status', 20);
            $t->string('reference', 100)->nullable();
            $t->text('data');
            $t->timestamps();
            $t->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('supplier_connections')->select('store_id', 'kind')->groupBy('store_id', 'kind')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Rollback requires resolving multiple supplier connections first.');
        }
        Schema::dropIfExists('otomax_events');
        Schema::dropIfExists('otomax_tickets');
        Schema::dropIfExists('otomax_imports');
        Schema::table('supplier_connections', function (Blueprint $t) {
            $t->unique(['store_id', 'kind']);
        });
        Schema::table('supplier_connections', function (Blueprint $t) {
            $t->dropUnique(['store_id', 'kind', 'slot']);
            $t->dropConstrainedForeignId('response_profile_id');
            $t->dropColumn(['name', 'settings', 'slot']);
        });
        Schema::dropIfExists('otomax_profiles');
    }
};
