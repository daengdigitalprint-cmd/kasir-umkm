<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Komisi teknisi khusus barbershop — TERPISAH dari commission_* aggregator makanan online.
     * - products: tarif override per layanan (menimpa tarif global stores.settings['barber_commission']).
     * - transactions: hasil perhitungan komisi saat pembayaran, nullable — hanya terisi
     *   untuk toko barbershop pada paket GrowUp ke atas dengan teknisi terpilih.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('barber_commission_mode', 10)->nullable()->after('service_duration_minutes');
            $table->decimal('barber_commission_value', 12, 2)->nullable()->after('barber_commission_mode');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('barber_commission_percent', 8, 4)->nullable()->after('commission_amount');
            $table->decimal('barber_commission_amount', 15, 2)->nullable()->after('barber_commission_percent');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['barber_commission_mode', 'barber_commission_value']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['barber_commission_percent', 'barber_commission_amount']);
        });
    }
};
