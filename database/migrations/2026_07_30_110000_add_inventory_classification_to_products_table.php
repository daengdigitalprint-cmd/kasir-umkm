<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 2 modul gudang/produksi: klasifikasi inventory produk, satuan dasar, dan average cost.
 *
 * Produk lama diberi tipe `sellable` dengan satuan dasar `pcs` agar alur retail existing
 * tidak berubah. Nilai `average_cost` diisi dari `cost_price` sebagai biaya awal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'inventory_type')) {
                $table->string('inventory_type', 32)->default('sellable')->after('category');
            }
            if (! Schema::hasColumn('products', 'base_unit')) {
                $table->string('base_unit', 16)->default('pcs')->after('unit');
            }
            if (! Schema::hasColumn('products', 'average_cost')) {
                $table->decimal('average_cost', 18, 6)->nullable()->after('cost_price');
            }
        });

        DB::table('products')->whereNull('inventory_type')->update(['inventory_type' => 'sellable']);
        DB::table('products')->whereNull('base_unit')->update(['base_unit' => 'pcs']);
        DB::table('products')
            ->whereNull('average_cost')
            ->whereNotNull('cost_price')
            ->update(['average_cost' => DB::raw('cost_price')]);

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                $table->index(['store_id', 'inventory_type']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'inventory_type']);
        });

        Schema::table('products', function (Blueprint $table) {
            foreach (['inventory_type', 'base_unit', 'average_cost'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
