<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kolom snapshot HPP pada transaction_items
        Schema::table('transaction_items', function (Blueprint $table) {
            if (! Schema::hasColumn('transaction_items', 'unit_cost_snapshot')) {
                $table->decimal('unit_cost_snapshot', 18, 6)->nullable()->after('tax_amount');
            }
            if (! Schema::hasColumn('transaction_items', 'total_cost_snapshot')) {
                $table->decimal('total_cost_snapshot', 18, 2)->nullable()->after('unit_cost_snapshot');
            }
            if (! Schema::hasColumn('transaction_items', 'gross_profit_snapshot')) {
                $table->decimal('gross_profit_snapshot', 18, 2)->nullable()->after('total_cost_snapshot');
            }
        });

        // Tabel alokasi konsumsi per item transaksi
        if (! Schema::hasTable('sale_consumption_allocations')) {
            Schema::create('sale_consumption_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transaction_item_id')->constrained()->cascadeOnDelete();
                $table->foreignId('production_batch_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('component_product_id')->constrained('products')->cascadeOnDelete();
                $table->decimal('quantity_consumed', 18, 4);
                $table->decimal('unit_cost', 18, 6);
                $table->decimal('total_cost', 18, 2);
                $table->timestamps();

                $table->index(['transaction_item_id']);
                $table->index(['production_batch_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_consumption_allocations');

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropColumn([
                'unit_cost_snapshot',
                'total_cost_snapshot',
                'gross_profit_snapshot',
            ]);
        });
    }
};
