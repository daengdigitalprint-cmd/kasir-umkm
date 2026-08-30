<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name', 50);                // Nama meja: "Meja 1", "VIP A", dll
            $table->string('code', 20)->nullable();     // Kode pendek: "T01", "VIP-A"
            $table->string('qr_code_url', 500)->nullable(); // URL QR code (gambar)
            $table->string('slug', 64)->unique();        // UUID slug untuk akses publik
            $table->boolean('is_active')->default(true);  // Status aktif/nonaktif
            $table->integer('capacity')->nullable();      // Kapasitas kursi (opsional)
            $table->string('location', 100)->nullable();  // Area/lokasi meja (opsional)
            $table->text('notes')->nullable();             // Catatan internal
            $table->timestamps();

            $table->index(['store_id', 'is_active']);
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
