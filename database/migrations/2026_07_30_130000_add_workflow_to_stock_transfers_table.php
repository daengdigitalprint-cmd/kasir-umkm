<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $transfers = Schema::getColumnListing('stock_transfers');
        $lines = Schema::getColumnListing('stock_transfer_lines');

        if (! in_array('status', $transfers)) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->string('status', 20)->default('draft')->after('transfer_number');
            });
        }
        if (! in_array('sent_at', $transfers)) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->timestamp('sent_at')->nullable()->after('notes');
            });
        }
        if (! in_array('received_at', $transfers)) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->timestamp('received_at')->nullable()->after('sent_at');
            });
        }
        if (! in_array('cancelled_at', $transfers)) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->timestamp('cancelled_at')->nullable()->after('received_at');
            });
        }
        if (! in_array('sent_by', $transfers)) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->foreignId('sent_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            });
        }
        if (! in_array('received_by', $transfers)) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->foreignId('received_by')->nullable()->after('sent_by')->constrained('users')->nullOnDelete();
            });
        }
        if (! in_array('cancelled_by', $transfers)) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->foreignId('cancelled_by')->nullable()->after('received_by')->constrained('users')->nullOnDelete();
            });
        }
        if (! in_array('cancellation_reason', $transfers)) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            });
        }

        if (! in_array('base_unit', $lines)) {
            Schema::table('stock_transfer_lines', function (Blueprint $table) {
                $table->string('base_unit', 10)->nullable()->after('quantity');
            });
        }
        if (! in_array('unit_cost_snapshot', $lines)) {
            Schema::table('stock_transfer_lines', function (Blueprint $table) {
                $table->decimal('unit_cost_snapshot', 18, 6)->nullable()->after('base_unit');
            });
        }
        if (! in_array('total_cost_snapshot', $lines)) {
            Schema::table('stock_transfer_lines', function (Blueprint $table) {
                $table->decimal('total_cost_snapshot', 18, 2)->nullable()->after('unit_cost_snapshot');
            });
        }

        // Transfer existing sudah langsung memutasi stok → tandai sebagai received.
        DB::table('stock_transfers')
            ->whereNull('status')
            ->orWhere('status', '')
            ->update([
                'status' => 'received',
                'received_at' => DB::raw('created_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('stock_transfer_lines', function (Blueprint $table) {
            $table->dropColumn(['base_unit', 'unit_cost_snapshot', 'total_cost_snapshot']);
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropForeign(['sent_by', 'received_by', 'cancelled_by']);
            $table->dropColumn([
                'status', 'sent_at', 'received_at', 'cancelled_at',
                'sent_by', 'received_by', 'cancelled_by', 'cancellation_reason',
            ]);
        });
    }
};
