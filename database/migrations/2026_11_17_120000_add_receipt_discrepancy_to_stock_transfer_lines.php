<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F5 Terima sebagian & selisih. Null = diterima penuh sesuai `quantity`
     * (semua transfer lama tetap bermakna sama).
     */
    public function up(): void
    {
        Schema::table('stock_transfer_lines', function (Blueprint $table) {
            $table->decimal('quantity_received', 18, 4)->nullable()->after('quantity');
            $table->string('discrepancy_reason', 255)->nullable()->after('quantity_received');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_lines', function (Blueprint $table) {
            $table->dropColumn(['quantity_received', 'discrepancy_reason']);
        });
    }
};
