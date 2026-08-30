<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 30);
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('draft');
            $table->decimal('multiplier', 10, 4)->default(1);
            $table->decimal('output_quantity_initial', 18, 4)->default(0);
            $table->decimal('output_quantity_remaining', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->foreignId('produced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('produced_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            // Snapshot recipe saat batch dibuat
            $table->string('recipe_name', 150)->nullable();
            $table->string('recipe_code', 50)->nullable();
            $table->unsignedInteger('recipe_version')->nullable();
            $table->decimal('recipe_output_quantity', 18, 4)->nullable();
            $table->string('recipe_output_unit', 10)->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'batch_number']);
            $table->index(['store_id', 'status']);
            $table->index(['product_id', 'status']);
        });

        Schema::create('production_batch_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity_required', 18, 4)->default(0);
            $table->decimal('quantity_consumed', 18, 4)->default(0);
            $table->string('base_unit', 10)->nullable();
            $table->decimal('unit_cost_snapshot', 18, 6)->nullable();
            $table->decimal('total_cost', 18, 2)->nullable();
            $table->timestamps();

            $table->index('production_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batch_lines');
        Schema::dropIfExists('production_batches');
    }
};
