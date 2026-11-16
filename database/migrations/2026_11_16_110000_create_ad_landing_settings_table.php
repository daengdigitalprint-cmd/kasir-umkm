<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pengaturan landing page iklan (/mulai) yang diisi Super Admin:
     * Meta Pixel, Conversions API, dan teks penawaran. Satu baris saja.
     */
    public function up(): void
    {
        Schema::create('ad_landing_settings', function (Blueprint $table) {
            $table->id();
            $table->string('meta_pixel_id', 30)->nullable();
            $table->text('meta_capi_token')->nullable(); // terenkripsi (cast di model)
            $table->string('meta_test_event_code', 40)->nullable();
            $table->string('offer_text', 160)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_landing_settings');
    }
};
