<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outlet Utama per grup usaha (organization_id): toko outlet pertama
     * yang dibuat dalam grup. Gudang Pusat tidak dihitung sebagai outlet utama.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('location_type');
        });

        // Backfill: outlet tertua (id terkecil) di tiap grup usaha menjadi Utama.
        $firstOutlets = DB::table('stores')
            ->select('organization_id', DB::raw('MIN(id) as first_id'))
            ->where('location_type', 'outlet')
            ->whereNotNull('organization_id')
            ->groupBy('organization_id')
            ->get();

        foreach ($firstOutlets as $row) {
            DB::table('stores')
                ->where('id', $row->first_id)
                ->update(['is_primary' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('is_primary');
        });
    }
};
