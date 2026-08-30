<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filename');
            $table->string('filepath');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('status', 20)->default('completed'); // pending, completed, failed
            $table->string('trigger_type', 20)->default('manual'); // manual, scheduled, restore
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('records_count')->default(0);
            $table->json('records_summary')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_histories');
    }
};
