<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan Stok kini punya dua jenis:
 * - transfer : pindah stok biasa antar lokasi (tanpa tagihan), perilaku lama.
 * - purchase : outlet MEMBELI ke gudang/CK — tiap barang punya harga, permintaan
 *              punya total tagihan dan status pembayaran (pemasukan bagi gudang,
 *              pengeluaran bagi outlet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_requests', function (Blueprint $table) {
            $table->string('mode', 20)->default('transfer')->after('status')->index();
            $table->decimal('total_amount', 15, 2)->default(0)->after('mode');
            $table->string('payment_status', 20)->nullable()->after('total_amount')->index();
            $table->timestamp('paid_at')->nullable()->after('payment_status');
            $table->foreignId('paid_by')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
            $table->string('payment_method', 30)->nullable()->after('paid_by');
            $table->string('payment_note', 255)->nullable()->after('payment_method');
        });

        Schema::table('stock_request_lines', function (Blueprint $table) {
            // Harga jual gudang per satuan dasar, dikunci saat disetujui.
            $table->decimal('unit_price', 15, 2)->nullable()->after('quantity_approved');
            // HPP gudang saat disetujui (untuk laba kotor gudang di laporan).
            $table->decimal('unit_cost', 18, 6)->nullable()->after('unit_price');
            // Jumlah yang ditagih: mengikuti jumlah yang benar-benar diterima outlet.
            $table->decimal('quantity_billed', 18, 4)->nullable()->after('unit_cost');
            $table->decimal('line_total', 15, 2)->nullable()->after('quantity_billed');
        });
    }

    public function down(): void
    {
        Schema::table('stock_request_lines', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'unit_cost', 'quantity_billed', 'line_total']);
        });

        Schema::table('stock_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn(['mode', 'total_amount', 'payment_status', 'paid_at', 'payment_method', 'payment_note']);
        });
    }
};
