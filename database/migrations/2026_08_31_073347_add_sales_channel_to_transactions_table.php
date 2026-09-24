<?php

use App\Enums\SalesChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambah kolom sales_channel + commission di tabel transactions.
 *
 * sales_channel: dari mana transaksi datang (walk_in, gofood, dll).
 * commission: hanya relevan untuk channel aggregator (GoFood/GrabFood/ShopeeFood).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('sales_channel', 32)->default(SalesChannel::WalkIn->value)->after('order_type');
            $table->decimal('commission_percent', 5, 2)->default(0)->after('tip_amount');
            $table->decimal('commission_amount', 15, 2)->default(0)->after('commission_percent');

            $table->index(['store_id', 'sales_channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'sales_channel', 'created_at']);
            $table->dropColumn(['sales_channel', 'commission_percent', 'commission_amount']);
        });
    }
};
