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
        Schema::table('ai_configurations', function (Blueprint $table) {
            $table->boolean('supports_function_calling')->default(true)->after('temperature');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_configurations', function (Blueprint $table) {
            $table->dropColumn('supports_function_calling');
        });
    }
};
