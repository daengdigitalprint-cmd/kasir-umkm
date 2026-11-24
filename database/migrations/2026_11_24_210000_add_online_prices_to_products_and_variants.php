<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Harga toko online per aplikasi (GoFood/GrabFood/ShopeeFood).
 * Bentuk: {"gofood": 18000, "grabfood": 18000}. Kanal yang tidak diisi memakai harga toko.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('online_prices')->nullable()->after('wholesale_price');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->json('online_prices')->nullable()->after('wholesale_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('online_prices');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('online_prices');
        });
    }
};
