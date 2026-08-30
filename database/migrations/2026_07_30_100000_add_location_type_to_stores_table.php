<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 1 modul gudang/produksi: klasifikasi lokasi menjadi Gudang Pusat atau Outlet.
 * Semua toko lama dianggap outlet agar alur penjualan existing tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        if (! Schema::hasColumn('stores', 'location_type')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->string('location_type', 32)->default('outlet')->after('business_type');
            });
        }

        DB::table('stores')->whereNull('location_type')->update(['location_type' => 'outlet']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('stores') || ! Schema::hasColumn('stores', 'location_type')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('location_type');
        });
    }
};
