<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kartu Stempel Digital: pelanggan mengumpulkan stempel per kunjungan/produk,
 * kartu penuh = 1 hadiah siap ditukar. Aturan per toko di stores.settings['stamp_card'].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('stamp_count')->default(0);
            $table->unsignedInteger('stamp_rewards_ready')->default(0);
            $table->unsignedInteger('stamp_rewards_redeemed')->default(0);
            $table->timestamp('stamp_last_at')->nullable();
            // Kode acak untuk tautan kartu publik (/k/{token}) dan QR yang dipindai kasir.
            $table->string('stamp_card_token', 40)->nullable()->unique();
        });

        Schema::create('stamp_card_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            // earn | reverse | redeem | adjust | expire
            $table->string('type', 16);
            // Perubahan stempel (bertanda); untuk redeem = 0, perubahan hadiah di rewards_delta.
            $table->integer('stamps')->default(0);
            $table->integer('rewards_delta')->default(0);
            $table->unsignedInteger('count_after')->default(0);
            $table->unsignedInteger('rewards_after')->default(0);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'customer_id']);
            $table->index(['transaction_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stamp_card_ledgers');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['stamp_card_token']);
            $table->dropColumn(['stamp_count', 'stamp_rewards_ready', 'stamp_rewards_redeemed', 'stamp_last_at', 'stamp_card_token']);
        });
    }
};
