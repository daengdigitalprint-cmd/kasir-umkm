<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('order_status', 20)->default('pending')->after('status');
            $table->string('payment_status', 20)->default('unpaid')->after('payment_method');
            $table->timestamp('accepted_at')->nullable()->after('completed_at');
            $table->index(['store_id', 'order_status', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'order_status', 'payment_status']);
            $table->dropColumn(['order_status', 'payment_status', 'accepted_at']);
        });
    }
};
