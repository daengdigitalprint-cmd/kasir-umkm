<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // Nama biaya bebas (mis. "Ongkos kirim", "Biaya bongkar muat").
            $table->string('name', 255);
            $table->decimal('amount', 15, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Dipakai untuk saran autocomplete nama biaya yang pernah dipakai toko.
            $table->index(['store_id', 'name']);
            $table->index('purchase_id');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('charges_amount', 15, 2)->nullable()->after('dpp_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('charges_amount');
        });

        Schema::dropIfExists('purchase_charges');
    }
};
