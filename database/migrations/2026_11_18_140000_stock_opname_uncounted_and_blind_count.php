<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock opname:
 * - counted_quantity boleh kosong = "belum dihitung" (sebelumnya otomatis diisi stok sistem,
 *   sehingga produk yang terlewat ikut diposting sebagai "pas").
 * - counted_at per baris + movement_during_count: penjualan/penerimaan antara sesi dibuka
 *   dan saat baris dihitung, agar selisih tidak terhitung dua kali.
 * - blind_count disimpan per sesi (dulu pilihannya tidak pernah tersimpan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustment_lines', function (Blueprint $table) {
            $table->decimal('counted_quantity', 18, 4)->nullable()->default(null)->change();
            $table->dateTime('counted_at')->nullable()->after('counted_quantity');
            $table->decimal('movement_during_count', 18, 4)->default(0)->after('counted_at');
        });

        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->boolean('blind_count')->default(false)->after('counted_at');
        });

        // Sesi opname draft lama: baris yang tidak pernah disentuh (isi = stok sistem,
        // belum pernah diperbarui) sebenarnya belum dihitung.
        $draftIds = \Illuminate\Support\Facades\DB::table('stock_adjustments')
            ->where('type', 'opname')
            ->where('status', 'draft')
            ->pluck('id');

        if ($draftIds->isNotEmpty()) {
            \Illuminate\Support\Facades\DB::table('stock_adjustment_lines')
                ->whereIn('stock_adjustment_id', $draftIds)
                ->whereColumn('counted_quantity', 'system_quantity')
                ->whereColumn('updated_at', 'created_at')
                ->update(['counted_quantity' => null, 'variance_quantity' => 0, 'variance_value' => 0]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropColumn('blind_count');
        });

        Schema::table('stock_adjustment_lines', function (Blueprint $table) {
            $table->dropColumn(['counted_at', 'movement_during_count']);
        });

        \Illuminate\Support\Facades\DB::table('stock_adjustment_lines')
            ->whereNull('counted_quantity')
            ->update(['counted_quantity' => \Illuminate\Support\Facades\DB::raw('system_quantity')]);

        Schema::table('stock_adjustment_lines', function (Blueprint $table) {
            $table->decimal('counted_quantity', 18, 4)->default(0)->nullable(false)->change();
        });
    }
};
