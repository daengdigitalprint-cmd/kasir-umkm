<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->string('status', 20)->default('available')->after('batch_code');
        });

        Schema::table('product_batches', function (Blueprint $table) {
            $table->index(['product_id', 'status', 'quantity_remaining']);
        });
    }

    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'status', 'quantity_remaining']);
            $table->dropColumn('status');
        });
    }
};
