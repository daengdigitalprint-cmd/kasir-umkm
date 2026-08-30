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
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('tax_enabled')->default(false)->after('currency');
            $table->decimal('tax_rate', 5, 2)->default(11.00)->after('tax_enabled');
            $table->string('tax_name', 50)->default('PPN')->after('tax_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['tax_enabled', 'tax_rate', 'tax_name']);
        });
    }
};
