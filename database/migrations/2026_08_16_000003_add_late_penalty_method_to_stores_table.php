<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah metode denda keterlambatan (salah satu: per hari / per menit).
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('late_penalty_method', 20)->default('per_day')->after('late_penalty_per_minute');
        });
    }

    /**
     * Hapus kolom metode denda keterlambatan.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('late_penalty_method');
        });
    }
};
