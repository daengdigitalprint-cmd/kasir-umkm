<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Porsi tunai yang benar-benar masuk laci untuk setiap transaksi.
 *
 * Sebelum ada split tender, saldo kas shift dihitung dengan
 * `where('payment_method', 'cash')->sum('total_amount')`. Rumus itu salah
 * begitu satu transaksi dibayar dengan dua metode. Kolom ini menyimpan porsi
 * tunainya langsung sehingga perhitungan cukup `sum('cash_amount')`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('cash_amount', 15, 2)->default(0)->after('amount_paid');
        });

        // Backfill menyamai rumus lama persis: transaksi tunai penuh = total,
        // selain itu nol. Dengan begitu angka historis tidak berubah sedikit pun.
        DB::table('transactions')
            ->where('status', 'completed')
            ->where('payment_method', 'cash')
            ->update(['cash_amount' => DB::raw('total_amount')]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('cash_amount');
        });
    }
};
