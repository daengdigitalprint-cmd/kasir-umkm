<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_deletion_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable();
            $table->string('transaction_number', 64);
            $table->foreignId('deleted_by')->constrained('users')->cascadeOnDelete();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->unsignedSmallInteger('items_count')->default(0);
            $table->json('restoration_details')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_deletion_logs');
    }
};
