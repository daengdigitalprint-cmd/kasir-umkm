<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penugasan teknisi (role barber) pada transaksi POS dan entri antrian.
     * Nullable — hanya terisi untuk toko barbershop / salon.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('barber_user_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();

            $table->index(['store_id', 'barber_user_id', 'created_at']);
        });

        Schema::table('queue_entries', function (Blueprint $table) {
            $table->foreignId('barber_user_id')->nullable()->after('transaction_id')
                ->constrained('users')->nullOnDelete();

            $table->index(['store_id', 'barber_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'barber_user_id', 'created_at']);
            $table->dropConstrainedForeignId('barber_user_id');
        });

        Schema::table('queue_entries', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'barber_user_id', 'status']);
            $table->dropConstrainedForeignId('barber_user_id');
        });
    }
};
