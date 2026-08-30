<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->string('status', 16)->default('draft')->after('notes');
            $table->timestamp('sent_at')->nullable()->after('status');
            $table->timestamp('received_at')->nullable()->after('sent_at');
            $table->timestamp('cancelled_at')->nullable()->after('received_at');
            $table->foreignId('sent_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->after('sent_by')->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->after('received_by')->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            $table->index(['status', 'created_at']);
            $table->index(['from_store_id', 'status', 'created_at']);
            $table->index(['to_store_id', 'status', 'created_at']);
        });

        DB::table('stock_transfers')->update([
            'status' => 'received',
            'sent_at' => DB::raw('created_at'),
            'received_at' => DB::raw('created_at'),
            'sent_by' => DB::raw('user_id'),
            'received_by' => DB::raw('user_id'),
        ]);

        Schema::table('stock_transfer_lines', function (Blueprint $table) {
            $table->string('base_unit', 20)->nullable()->after('quantity');
            $table->string('inventory_type', 32)->nullable()->after('base_unit');
            $table->decimal('unit_cost_snapshot', 18, 6)->nullable()->after('inventory_type');
            $table->decimal('total_cost_snapshot', 18, 2)->nullable()->after('unit_cost_snapshot');
        });

        DB::table('stock_transfer_lines')
            ->join('products', 'products.id', '=', 'stock_transfer_lines.from_product_id')
            ->select('stock_transfer_lines.id', 'products.base_unit', 'products.inventory_type')
            ->orderBy('stock_transfer_lines.id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('stock_transfer_lines')->where('id', $row->id)->update([
                        'base_unit' => $row->base_unit,
                        'inventory_type' => $row->inventory_type,
                    ]);
                }
            }, 'stock_transfer_lines.id', 'id');
    }

    public function down(): void
    {
        Schema::table('stock_transfer_lines', function (Blueprint $table) {
            $table->dropColumn([
                'base_unit',
                'inventory_type',
                'unit_cost_snapshot',
                'total_cost_snapshot',
            ]);
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['from_store_id', 'status', 'created_at']);
            $table->dropIndex(['to_store_id', 'status', 'created_at']);
            $table->dropConstrainedForeignId('sent_by');
            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'status',
                'sent_at',
                'received_at',
                'cancelled_at',
                'cancellation_reason',
            ]);
        });
    }
};
