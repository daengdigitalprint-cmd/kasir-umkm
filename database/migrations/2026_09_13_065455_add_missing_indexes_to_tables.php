<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['store_id', 'is_active']);
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->index(['store_id', 'created_at']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->index(['store_id', 'is_paid']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'is_active']);
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'created_at']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'is_paid']);
        });
    }
};
