<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durasi layanan (menit) untuk produk bertipe jasa — barbershop / salon.
     * NULL untuk produk biasa (tidak berpengaruh ke jenis usaha lain).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('service_duration_minutes')->nullable()->after('unit');

            $table->index(['store_id', 'service_duration_minutes']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'service_duration_minutes']);
            $table->dropColumn('service_duration_minutes');
        });
    }
};
