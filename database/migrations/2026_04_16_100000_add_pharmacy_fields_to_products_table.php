<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('pharmacy_sale_class', 20)->nullable()->after('is_bundle');
            $table->string('pharmacy_registration_no', 128)->nullable()->after('pharmacy_sale_class');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['pharmacy_sale_class', 'pharmacy_registration_no']);
        });
    }
};
