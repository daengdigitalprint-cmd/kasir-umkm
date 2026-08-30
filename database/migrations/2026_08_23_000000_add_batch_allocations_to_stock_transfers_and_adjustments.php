<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustment_lines', function (Blueprint $table) {
            $table->string('batch_code', 120)->nullable()->after('product_batch_id');
            $table->date('expiry_date')->nullable()->after('batch_code');
        });

        Schema::create('stock_transfer_line_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_line_id')->constrained()->cascadeOnDelete();
            $table->string('batch_code', 120)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->timestamps();

            $table->index('stock_transfer_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_line_batches');

        Schema::table('stock_adjustment_lines', function (Blueprint $table) {
            $table->dropColumn(['batch_code', 'expiry_date']);
        });
    }
};
