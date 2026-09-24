<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('last_purchase_at')->nullable()->after('is_active');
            $table->decimal('last_purchase_amount', 15, 2)->nullable()->after('last_purchase_at');
            $table->index(['store_id', 'is_active']);
            $table->index(['store_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'is_active']);
            $table->dropIndex(['store_id', 'name']);
            $table->dropColumn(['last_purchase_at', 'last_purchase_amount']);
        });
    }
};
