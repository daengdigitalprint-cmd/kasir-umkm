<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom pph21_enabled di tabel stores untuk modul payroll.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('pph21_enabled')->default(false)->after('bpjs_jkm');
        });
    }

    /**
     * Hapus kolom pph21_enabled.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('pph21_enabled');
        });
    }
};
