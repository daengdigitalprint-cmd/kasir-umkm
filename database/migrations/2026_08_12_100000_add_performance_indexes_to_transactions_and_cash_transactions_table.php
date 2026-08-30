<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Composite index untuk query dashboard/laporan paling sering:
            // ->where('store_id', ...)->where('status', 'completed')->whereBetween('created_at', ...)
            $table->index(['store_id', 'status', 'created_at'], 'idx_transactions_store_status_date');
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            // Composite index untuk query cash shift summary:
            // ->where('cash_shift_id', ...)->where('type', 'in'|'out')
            $table->index(['cash_shift_id', 'type'], 'idx_cash_trans_shift_type');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_transactions_store_status_date');
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_cash_trans_shift_type');
        });
    }
};
