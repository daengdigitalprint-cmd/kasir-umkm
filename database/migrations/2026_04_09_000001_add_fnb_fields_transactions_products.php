<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('order_type', 20)->nullable()->after('payment_method');
            $table->string('table_reference', 64)->nullable()->after('order_type');
            $table->decimal('service_charge_percent', 5, 2)->nullable()->after('discount_amount');
            $table->decimal('service_charge_amount', 15, 2)->default(0)->after('service_charge_percent');
            $table->decimal('tip_amount', 15, 2)->default(0)->after('tax_amount');
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->json('modifiers')->nullable()->after('product_snapshot');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->json('modifier_options')->nullable()->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'order_type',
                'table_reference',
                'service_charge_percent',
                'service_charge_amount',
                'tip_amount',
            ]);
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropColumn('modifiers');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('modifier_options');
        });
    }
};
