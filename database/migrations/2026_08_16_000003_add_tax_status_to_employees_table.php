<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom status pajak (PTKP) di tabel employees untuk perhitungan PPh 21.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('tax_status', 10)->default('TK/0'); // TK/0, TK/1, K/0, K/1, K/2, K/3
            $table->unsignedTinyInteger('dependents')->default(0); // Jumlah tanggungan (0-3)
        });
    }

    /**
     * Hapus kolom status pajak.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['tax_status', 'dependents']);
        });
    }
};
