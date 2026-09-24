<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable();
            $table->date('alert_date');
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            // Idempoten: satu peringatan per user+toko+jenis per hari.
            $table->unique(['user_id', 'store_id', 'type', 'alert_date']);
            $table->index(['user_id', 'is_read', 'alert_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_alerts');
    }
};
