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
        Schema::create('module_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module_name', 50);          // Nama modul: scan_meja, queue_module, dll
            $table->string('action', 30);                // enabled, disabled
            $table->boolean('status_sebelum')->default(false);
            $table->boolean('status_sesudah')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('keterangan')->nullable();       // Catatan opsional
            $table->timestamps();

            $table->index(['store_id', 'module_name']);
            $table->index(['store_id', 'created_at']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('module_activity_logs');
    }
};
