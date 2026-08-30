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
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('type', ['in', 'out'])->index(); // in = uang masuk, out = uang keluar

            // Kategori sesuai desain UI
            // revenue = Pendapatan Lain (Nambah profit)
            // non_revenue = Non Pendapatan (Kas murni/modal awal)
            // expense = Biaya (Mengurangi profit)
            // non_expense = Non Biaya (Setoran kas murni)
            $table->string('category', 50)->index();

            $table->decimal('amount', 15, 4);
            $table->string('party_name')->nullable(); // "Terima Dari" atau "Dibayar Ke"
            $table->string('notes')->nullable(); // Keterangan

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
    }
};
