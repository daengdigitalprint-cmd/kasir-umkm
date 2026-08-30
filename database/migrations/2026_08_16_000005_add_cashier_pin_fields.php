<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('cashier_login_mode', 10)->default('email')->after('pph21_enabled');
        });

        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('user_id');
            $table->index(['store_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'employee_id']);
            $table->dropColumn('employee_id');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('cashier_login_mode');
        });
    }
};
