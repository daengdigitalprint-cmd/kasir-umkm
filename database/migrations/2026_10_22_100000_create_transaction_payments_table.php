<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian pembayaran per transaksi (split tender).
 *
 * Satu transaksi bisa dibayar dengan beberapa metode sekaligus, misalnya
 * sebagian tunai dan sisanya QRIS. Kolom transactions.payment_method tetap ada
 * dan diisi metode dengan nominal terbesar supaya tampilan lama tidak berubah,
 * sedangkan rincian sebenarnya dibaca dari tabel ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20);
            $table->decimal('amount', 15, 2);
            $table->string('reference', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['transaction_id', 'sort_order']);
            $table->index(['store_id', 'method']);
        });

        // Transaksi lama: satu baris pembayaran sesuai metode yang tercatat,
        // supaya laporan rincian metode tidak kehilangan riwayat.
        DB::statement(<<<'SQL'
            INSERT INTO transaction_payments (transaction_id, store_id, method, amount, sort_order, created_at, updated_at)
            SELECT id, store_id, COALESCE(payment_method, 'cash'), total_amount, 0, COALESCE(completed_at, created_at), COALESCE(completed_at, created_at)
            FROM transactions
            WHERE status = 'completed'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_payments');
    }
};
