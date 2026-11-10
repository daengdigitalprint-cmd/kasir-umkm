<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_redemptions', function (Blueprint $table) {
            // Kapan kode voucher ditebus di kasir — null berarti belum pernah dipakai.
            $table->timestamp('used_at')->nullable()->after('voucher_code');
            // Transaksi yang memakai voucher, untuk audit & mencegah pemakaian ganda.
            $table->foreignId('used_transaction_id')->nullable()->after('used_at')
                ->constrained('transactions')->nullOnDelete();

            $table->index(['store_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_redemptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('used_transaction_id');
            $table->dropIndex(['store_id', 'used_at']);
            $table->dropColumn('used_at');
        });
    }
};
