<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_number', 32);
            $table->string('status', 20)->default('open');
            $table->date('expected_pickup_at')->nullable();
            $table->decimal('deposit_required', 12, 2)->default(0);
            $table->decimal('deposit_received', 12, 2)->default(0);
            $table->decimal('estimated_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('fulfilled_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'order_number']);
            $table->index(['store_id', 'status']);
        });

        Schema::create('pre_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pre_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 12, 2);
            $table->string('product_name', 255);
            $table->json('modifiers')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_order_lines');
        Schema::dropIfExists('pre_orders');
    }
};
