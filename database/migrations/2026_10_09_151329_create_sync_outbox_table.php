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
        Schema::create('sync_outbox', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_uuid')->unique()->comment('UUID lokal unik untuk idempotency');
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity_type')->comment('Tipe entitas, misal: transaction, shift, cash_movement');
            $table->string('action')->default('create')->comment('create, update, delete');
            $table->json('payload')->comment('Data JSON yang akan dikirim ke server');
            $table->string('status')->default('pending')->comment('pending, syncing, synced, failed');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'entity_type']);
            $table->index(['store_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_outbox');
    }
};
