<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('legal_name')->nullable();
            $table->text('nik')->nullable();
            $table->text('address')->nullable();
            $table->string('ktp_path')->nullable();
            $table->string('verification_status')->default('unsubmitted');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('document_purge_at')->nullable()->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['phone_verified_at', 'legal_name', 'nik', 'address', 'ktp_path', 'verification_status', 'rejection_reason', 'submitted_at', 'reviewed_at', 'document_purge_at']);
        });
    }
};
