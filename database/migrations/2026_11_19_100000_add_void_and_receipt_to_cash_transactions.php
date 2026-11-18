<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kas masuk/keluar: foto bukti, persetujuan PIN, dan pembatalan entri.
 * Entri batal tidak dihapus agar jejak audit tetap ada; jurnalnya dibalik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->string('receipt_path')->nullable()->after('notes');
            $table->boolean('approved_with_pin')->default(false)->after('receipt_path');
            $table->timestamp('voided_at')->nullable()->after('approved_with_pin');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['receipt_path', 'approved_with_pin', 'voided_at', 'void_reason']);
        });
    }
};
