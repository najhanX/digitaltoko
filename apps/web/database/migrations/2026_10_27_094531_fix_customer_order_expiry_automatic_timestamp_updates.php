<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyOrders = false;
        $offset = 0;
        if (DB::getDriverName() === 'mysql') {
            $column = DB::selectOne("SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_orders' AND COLUMN_NAME = 'expires_at'");
            $legacyOrders = str_contains(strtolower($column->EXTRA ?? ''), 'on update');
            $offset = (int) DB::selectOne('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS seconds')->seconds;
        }
        foreach (['customer_orders', 'customer_inquiries', 'otomax_imports'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dateTime('expires_at')->change();
            });
        }
        // Recover only ordinary invoices with the exact signature of the legacy automatic rewrite.
        // Inquiry deadlines and deliberately adjusted expiries are left untouched.
        if ($legacyOrders) {
            DB::table('customer_orders')->whereNull('inquiry_id')
                ->whereRaw('ABS(TIMESTAMPDIFF(SECOND, updated_at, expires_at) - ?) <= 2', [$offset])
                ->whereRaw('expires_at > DATE_ADD(created_at, INTERVAL 15 MINUTE)')
                ->update(['expires_at' => DB::raw('DATE_ADD(created_at, INTERVAL 15 MINUTE)')]);
        }
    }

    public function down(): void
    {
        // Retain fixed deadlines: restoring implicit ON UPDATE behavior would corrupt invoices again.
    }
};
