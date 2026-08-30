<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom denda keterlambatan di tabel stores untuk modul payroll.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->decimal('late_penalty_per_day', 10, 2)->default(10000);
            $table->decimal('late_penalty_per_minute', 10, 2)->default(100);
        });
    }

    /**
     * Hapus kolom denda keterlambatan.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['late_penalty_per_day', 'late_penalty_per_minute']);
        });
    }
};
