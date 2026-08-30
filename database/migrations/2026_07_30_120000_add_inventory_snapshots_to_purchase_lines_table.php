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
            $table->string('input_unit', 20)->nullable()->after('unit_cost');
            $table->decimal('quantity_input', 18, 4)->nullable()->after('input_unit');
            $table->string('base_unit', 20)->nullable()->after('quantity_input');
            $table->decimal('qty_base', 18, 4)->nullable()->after('base_unit');
            $table->decimal('unit_cost_input', 18, 2)->nullable()->after('qty_base');
            $table->decimal('unit_cost_base', 18, 6)->nullable()->after('unit_cost_input');
            $table->decimal('subtotal', 18, 2)->nullable()->after('unit_cost_base');
        });

        DB::table('purchase_lines')->update([
            'quantity_input' => DB::raw('quantity'),
            'qty_base' => DB::raw('quantity'),
            'unit_cost_input' => DB::raw('unit_cost'),
            'unit_cost_base' => DB::raw('unit_cost'),
            'subtotal' => DB::raw('quantity * unit_cost'),
        ]);

        DB::table('purchase_lines')
            ->join('products', 'products.id', '=', 'purchase_lines.product_id')
            ->select('purchase_lines.id', 'products.base_unit')
            ->orderBy('purchase_lines.id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('purchase_lines')
                        ->where('id', $row->id)
                        ->update([
                            'input_unit' => $row->base_unit,
                            'base_unit' => $row->base_unit,
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
            $table->dropColumn([
                'input_unit',
                'quantity_input',
                'base_unit',
                'qty_base',
                'unit_cost_input',
                'unit_cost_base',
                'subtotal',
            ]);
        });
    }
};
