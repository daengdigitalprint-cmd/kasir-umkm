<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saldo poin melekat pada record Customer per toko.
        Schema::table('customers', function (Blueprint $table) {
            $table->integer('points_balance')->default(0)->after('total_orders');
            $table->integer('points_earned_total')->default(0)->after('points_balance');
            $table->integer('points_redeemed_total')->default(0)->after('points_earned_total');
        });

        // Aturan & pengaturan program loyalitas per toko disimpan di stores.settings['loyalty'].

        // Katalog hadiah yang dapat ditukar dengan poin.
        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('description')->nullable();
            // voucher = potongan harga, free_product = produk gratis, service = layanan eksklusif
            $table->string('type', 20)->default('voucher');
            $table->integer('points_cost');
            // Nilai voucher (Rp) bila type = voucher.
            $table->decimal('voucher_value', 12, 2)->nullable();
            // null = stok tidak terbatas.
            $table->integer('stock')->nullable();
            $table->integer('redeemed_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'is_active']);
        });

        // Buku besar (ledger) poin — sumber kebenaran setiap perubahan saldo.
        Schema::create('loyalty_point_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // earn, redeem, expire, adjust, bonus_birthday, bonus_referral
            $table->string('type', 24);
            // + untuk perolehan, - untuk penukaran/kadaluarsa.
            $table->integer('points');
            // Saldo setelah baris ini diterapkan (untuk audit & tampilan riwayat).
            $table->integer('balance_after');
            // Sisa poin yang belum terpakai dari baris earn (untuk FIFO kadaluarsa).
            $table->integer('points_remaining')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->string('description', 255)->nullable();
            // Relasi polymorphic ringan ke sumber (transaction, redemption, dll).
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            // Pengguna (staf) yang memicu perubahan — untuk audit.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Hash integritas untuk mendeteksi manipulasi baris ledger.
            $table->string('integrity_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['store_id', 'customer_id']);
            $table->index(['customer_id', 'type']);
            $table->index(['type', 'expires_at']);
            $table->index(['source_type', 'source_id']);
        });

        // Catatan penukaran hadiah.
        Schema::create('loyalty_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loyalty_reward_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reward_name', 255);
            $table->string('reward_type', 20);
            $table->integer('points_spent');
            $table->decimal('voucher_value', 12, 2)->nullable();
            // pending, fulfilled, cancelled
            $table->string('status', 20)->default('fulfilled');
            // Kode voucher unik yang dihasilkan bila hadiah berupa voucher.
            $table->string('voucher_code', 40)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['customer_id']);
        });

        // Notifikasi loyalitas (in-app untuk staf + log semua channel).
        Schema::create('loyalty_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            // points_earned, points_expiring, redemption_success, promo
            $table->string('type', 30);
            // in_app, email, sms
            $table->string('channel', 12)->default('in_app');
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->timestamp('read_at')->nullable();
            // Status pengiriman untuk channel email/sms (logged saja).
            $table->string('delivery_status', 20)->default('logged');
            $table->timestamps();

            $table->index(['store_id', 'read_at']);
            $table->index(['store_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_notifications');
        Schema::dropIfExists('loyalty_redemptions');
        Schema::dropIfExists('loyalty_point_ledgers');
        Schema::dropIfExists('loyalty_rewards');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['points_balance', 'points_earned_total', 'points_redeemed_total']);
        });
    }
};
