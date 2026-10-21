<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->timestamp('closure_requested_at')->nullable()->index();
            $table->string('purge_origin', 12)->nullable();
        });
        DB::table('stores')->where('status', 'suspended_manual')
            ->update(['closure_requested_at' => DB::raw('updated_at'), 'retention_state' => 'requested']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['closure_requested_at', 'purge_origin']));
    }
};
