<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_lines')) {
            return;
        }

        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->string('product_name_snapshot', 255)->nullable()->after('product_variant_id');
            $table->string('sku_snapshot', 255)->nullable()->after('product_name_snapshot');
        });

        // Isi snapshot dari data produk existing agar dokumen historis konsisten
        DB::table('purchase_lines')
            ->join('products', 'products.id', '=', 'purchase_lines.product_id')
            ->select('purchase_lines.id', 'products.name as pname', 'products.sku as psku')
            ->orderBy('purchase_lines.id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('purchase_lines')
                        ->where('id', $row->id)
                        ->update([
                            'product_name_snapshot' => $row->pname,
                            'sku_snapshot' => $row->psku,
                        ]);
                }
            }, 'purchase_lines.id', 'id');
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase_lines')) {
            return;
        }

        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropColumn(['product_name_snapshot', 'sku_snapshot']);
        });
    }
};
