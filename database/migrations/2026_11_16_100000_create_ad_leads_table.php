<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lead dari landing page iklan (/mulai). Satu baris per nomor WhatsApp:
     * pengisian ulang memperbarui jawaban, bukan menambah baris.
     */
    public function up(): void
    {
        Schema::create('ad_leads', function (Blueprint $table) {
            $table->id();

            // Kontak
            $table->string('name', 100);
            $table->string('business_name', 120);
            $table->string('whatsapp', 20)->unique(); // dinormalkan: 628xxxx
            $table->string('city', 100)->nullable();

            // Jawaban penyaring (kunci dari App\Support\AdLeadQualifier)
            $table->string('business_type', 30);
            $table->string('business_status', 30);
            $table->string('daily_transactions', 30);
            $table->string('start_timeline', 30);

            // Hasil penilaian
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('tier', 10)->index(); // hot|warm|cold

            // Tindak lanjut oleh tim
            $table->string('status', 20)->default('new')->index(); // new|contacted|converted|dropped
            $table->text('notes')->nullable();
            $table->timestamp('contacted_at')->nullable();

            // Asal iklan
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 150)->nullable()->index();
            $table->string('utm_content', 150)->nullable();
            $table->string('utm_term', 150)->nullable();
            $table->string('fbclid', 255)->nullable();

            // Pencocokan Meta (Pixel + Conversions API)
            $table->string('fbp', 255)->nullable();
            $table->string('fbc', 255)->nullable();
            $table->uuid('event_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->unsignedSmallInteger('submissions')->default(1);
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_leads');
    }
};
