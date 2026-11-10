<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('debt_due_at')->nullable()->after('payment_status');

            // Tambahkan index untuk pencarian daftar dan aging piutang
            $table->index(['store_id', 'customer_id', 'payment_status'], 'idx_trx_receivables');
            $table->index(['store_id', 'payment_status', 'debt_due_at'], 'idx_trx_aging');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_trx_receivables');
            $table->dropIndex('idx_trx_aging');
            $table->dropColumn('debt_due_at');
        });
    }
};
