<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F3 Satuan beli & isi: satuan tambahan per produk untuk Pembelian
     * (mis. 1 karton = 24 pcs, 1 pcs bawang = 250 gram). Produk tanpa baris
     * di tabel ini tetap memakai konversi lama (satuan dasar & sedimensi).
     */
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 20);
            $table->decimal('factor', 18, 6);
            $table->boolean('is_purchase_default')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};
