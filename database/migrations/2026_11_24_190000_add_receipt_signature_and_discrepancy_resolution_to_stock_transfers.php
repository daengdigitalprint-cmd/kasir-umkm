<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F8 Bukti terima & penyelesaian selisih.
     *
     * - Transfer: nama + tanda tangan penerima, dan tautan ke transfer asal bila ini kiriman susulan.
     * - Baris: jenis selisih (kurang/rusak/lebih), jumlah lebih, dan penyelesaian oleh lokasi pengirim.
     *
     * Baris lama tanpa `discrepancy_type` tidak dianggap menunggu penyelesaian.
     */
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->string('receiver_name', 120)->nullable()->after('received_by');
            $table->mediumText('receiver_signature')->nullable()->after('receiver_name');
            $table->foreignId('followup_of_id')->nullable()->after('receiver_signature')
                ->constrained('stock_transfers')->nullOnDelete();
        });

        Schema::table('stock_transfer_lines', function (Blueprint $table) {
            $table->string('discrepancy_type', 20)->nullable()->after('quantity_received');
            $table->decimal('quantity_over', 18, 4)->nullable()->after('discrepancy_type');
            $table->string('resolution', 20)->nullable()->after('discrepancy_reason');
            $table->string('resolution_note', 255)->nullable()->after('resolution');
            $table->foreignId('resolved_by')->nullable()->after('resolution_note')->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['discrepancy_type', 'quantity_over', 'resolution', 'resolution_note', 'resolved_at']);
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('followup_of_id');
            $table->dropColumn(['receiver_name', 'receiver_signature']);
        });
    }
};
