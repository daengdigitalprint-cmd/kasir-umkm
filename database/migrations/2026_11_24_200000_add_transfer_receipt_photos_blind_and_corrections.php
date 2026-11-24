<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F9 Foto bukti selisih, penerimaan buta, dan pengajuan koreksi setelah diterima.
     *
     * - Transfer: penanda bahwa penerima menghitung tanpa melihat jumlah kirim.
     * - Baris: foto bukti selisih (path di disk public).
     * - Koreksi: pengajuan penerima untuk mengubah jumlah diterima, diputuskan lokasi pengirim.
     */
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->boolean('received_blind')->default(false)->after('receiver_signature');
        });

        Schema::table('stock_transfer_lines', function (Blueprint $table) {
            $table->string('discrepancy_photo', 255)->nullable()->after('discrepancy_reason');
        });

        Schema::create('stock_transfer_corrections', function (Blueprint $table) {
            $table->id();
            // Nama constraint ditulis eksplisit: nama otomatis Laravel untuk tabel ini melewati batas 64 karakter MySQL.
            $table->foreignId('stock_transfer_id')->constrained(indexName: 'st_corrections_transfer_fk')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('reason', 500);
            $table->foreignId('requested_by')->nullable()->constrained('users', indexName: 'st_corrections_requested_by_fk')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users', indexName: 'st_corrections_decided_by_fk')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamps();

            $table->index(['stock_transfer_id', 'status'], 'st_corrections_transfer_status_idx');
        });

        Schema::create('stock_transfer_correction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_correction_id')->constrained(indexName: 'st_correction_lines_correction_fk')->cascadeOnDelete();
            $table->foreignId('stock_transfer_line_id')->constrained(indexName: 'st_correction_lines_line_fk')->cascadeOnDelete();
            // Jumlah yang tiba menurut catatan saat diajukan, dan menurut penerima yang benar.
            $table->decimal('quantity_before', 18, 4);
            $table->decimal('quantity_after', 18, 4);
            $table->string('discrepancy_type', 20)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_correction_lines');
        Schema::dropIfExists('stock_transfer_corrections');

        Schema::table('stock_transfer_lines', function (Blueprint $table) {
            $table->dropColumn('discrepancy_photo');
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropColumn('received_blind');
        });
    }
};
