<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Berkas hasil hitung yang diunggah ke sebuah sesi stock opname.
 *
 * Tabel ini menyimpan proses pemrosesan (status + progres) beserta laporan
 * hasil validasi sebelum diaplikasikan ke lembar hitung. Stok hanya berubah
 * setelah sesi diposting, bukan saat berkas diunggah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opname_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('missing_mode', 20)->default('ignore');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('matched_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->text('error_message')->nullable();
            $table->longText('report')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['stock_adjustment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_imports');
    }
};
