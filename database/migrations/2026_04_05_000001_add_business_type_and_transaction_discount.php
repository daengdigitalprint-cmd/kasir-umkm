<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('business_type', 32)->default('general')->after('slug');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('discount_id')->nullable()->after('customer_id')->constrained('discounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_id');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('business_type');
        });
    }
};
