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
        Schema::table('stores', function (Blueprint $table) {
            $table->timestamp('admin_suspended_at')->nullable()->index();
            $table->text('admin_suspension_reason')->nullable();
        });
        Schema::create('store_admin_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->text('reason');
            $table->json('before');
            $table->json('after');
            $table->timestamps();
            $table->index(['store_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_admin_events');
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['admin_suspended_at', 'admin_suspension_reason']));
    }
};
