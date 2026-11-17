<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Permintaan Stok yang dibuat otomatis oleh jadwal (bukan diketik orang). */
    public function up(): void
    {
        Schema::table('stock_requests', function (Blueprint $table) {
            $table->boolean('is_automatic')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('stock_requests', function (Blueprint $table) {
            $table->dropColumn('is_automatic');
        });
    }
};
