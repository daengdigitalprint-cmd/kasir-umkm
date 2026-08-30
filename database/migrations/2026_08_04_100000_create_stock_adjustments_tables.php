<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen koreksi stok: penyesuaian insidental (adjustment) dan hitung fisik berkala (opname).
 * Keduanya berbagi satu pasang tabel karena menghasilkan mutasi yang identik secara akuntansi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('adjustment_number', 40);
            $table->string('type', 16)->default('adjustment');
            $table->string('reason_code', 24)->default('correction');
            $table->string('status', 24)->default('draft');
            $table->text('notes')->nullable();
            $table->dateTime('counted_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            // Nilai rupiah selisih; negatif berarti kerugian persediaan.
            $table->decimal('total_variance_value', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'adjustment_number']);
            $table->index(['store_id', 'status', 'created_at']);
            $table->index(['store_id', 'type', 'created_at']);
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_batch_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot stok saat baris dibuat; dipakai menghitung selisih walau toko tetap berjualan.
            $table->decimal('system_quantity', 18, 4)->default(0);
            $table->decimal('counted_quantity', 18, 4)->default(0);
            $table->decimal('variance_quantity', 18, 4)->default(0);

            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('variance_value', 18, 2)->default(0);
            $table->string('line_notes', 255)->nullable();
            $table->timestamps();

            $table->index(['stock_adjustment_id']);
            $table->index(['product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
    }
};
