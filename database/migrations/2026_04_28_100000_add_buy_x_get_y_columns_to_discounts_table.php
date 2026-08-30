<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('buy_x')->nullable()->after('value');
            $table->unsignedSmallInteger('get_y')->nullable()->after('buy_x');
        });
    }

    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->dropColumn(['buy_x', 'get_y']);
        });
    }
};
