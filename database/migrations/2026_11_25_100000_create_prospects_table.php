<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prospek penjualan: usaha yang datanya dipublikasikan sendiri di sumber
     * terbuka (saat ini OpenStreetMap). Alat internal tim pemasaran, tidak
     * terhubung ke data toko/pelanggan aplikasi.
     */
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table) {
            $table->id();

            // Asal data: satu baris per objek di sumbernya.
            $table->string('source', 20);            // osm | google (nanti)
            $table->string('source_id', 60);         // mis. node/123456
            $table->unique(['source', 'source_id']);

            // Profil usaha
            $table->string('name', 150);
            $table->string('category', 40);          // label, mis. "Kedai kopi"
            $table->string('keyword', 80);           // kata kunci pencarian asal
            $table->string('city', 100)->index();
            $table->string('address', 255)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            // Kontak publik
            $table->string('phone', 20)->nullable();           // dinormalkan: 62xxxx
            $table->boolean('phone_is_mobile')->default(false); // 08xx = kemungkinan WhatsApp
            $table->string('website', 255)->nullable();
            $table->string('instagram', 100)->nullable();       // nama akun tanpa @

            // Hanya terisi bila sumbernya menyediakan (Google Places).
            $table->decimal('rating', 2, 1)->nullable();
            $table->unsignedInteger('reviews_count')->nullable();

            // Penanda duplikat lintas objek/sumber (nomor telepon, atau nama + lokasi).
            $table->string('dedupe_key', 190)->index();

            // Prioritas & tindak lanjut oleh tim
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('status', 20)->default('new')->index(); // new|contacted|interested|not_interested
            $table->text('notes')->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();       // terakhir muncul di hasil pencarian
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospects');
    }
};
