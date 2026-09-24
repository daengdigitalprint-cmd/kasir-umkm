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
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('allow_negative_stock')->default(false)->after('unlimited_stock');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->boolean('allow_negative_stock')->default(false)->after('min_stock_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('allow_negative_stock');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('allow_negative_stock');
        });
    }
};
