<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanda tangan digital pada laporan tutup shift: kasir yang menyerahkan kas dan pengelola yang menerima.
 * Disimpan sebagai gambar PNG (data URL) beserta nama penanda tangan dan waktunya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->mediumText('cashier_signature')->nullable()->after('closing_notes');
            $table->string('cashier_signed_name')->nullable()->after('cashier_signature');
            $table->timestamp('cashier_signed_at')->nullable()->after('cashier_signed_name');
            $table->mediumText('manager_signature')->nullable()->after('cashier_signed_at');
            $table->string('manager_signed_name')->nullable()->after('manager_signature');
            $table->foreignId('manager_signed_by')->nullable()->after('manager_signed_name')->constrained('users')->nullOnDelete();
            $table->timestamp('manager_signed_at')->nullable()->after('manager_signed_by');
        });
    }

    public function down(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_signed_by');
            $table->dropColumn([
                'cashier_signature',
                'cashier_signed_name',
                'cashier_signed_at',
                'manager_signature',
                'manager_signed_name',
                'manager_signed_at',
            ]);
        });
    }
};
