<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 50)->comment('sewa, gaji, listrik, air, internet, transport, lainnya');
            $table->string('description', 255);
            $table->decimal('amount', 15, 2);
            $table->date('expense_date');
            $table->string('payment_method', 20)->default('cash')->comment('cash, transfer, ewallet');
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'expense_date']);
            $table->index(['store_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_expenses');
    }
};
