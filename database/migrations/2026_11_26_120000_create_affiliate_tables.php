<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Program afiliasi: afiliator membagikan link, mendapat komisi dari pembayaran
 * langganan toko yang mendaftar lewat link itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code', 20)->unique();
            $table->string('status', 20)->default('active'); // active | suspended
            // Tarif khusus afiliator ini; null = ikut config('affiliate.commission_percent').
            $table->decimal('commission_percent', 5, 2)->nullable();
            // Tujuan transfer komisi.
            $table->string('payout_provider', 60)->nullable(); // nama bank / e-wallet
            $table->string('payout_account_number', 40)->nullable();
            $table->string('payout_account_holder', 100)->nullable();
            $table->timestamp('agreed_terms_at')->nullable();
            $table->timestamps();
        });

        Schema::create('affiliate_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('landing_path', 255)->nullable();
            $table->string('ip_hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->index(['affiliate_id', 'created_at']);
            $table->index(['affiliate_id', 'ip_hash', 'created_at']);
        });

        Schema::create('affiliate_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            // Satu akun hanya bisa dirujuk satu afiliator, selamanya.
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            // Diisi saat toko pertama dibuat: komisi dihitung per grup usaha.
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('referred_name', 255)->nullable(); // salinan nama, bertahan bila akun dihapus
            $table->string('source', 20)->default('link'); // link | code
            $table->timestamp('first_paid_at')->nullable();
            $table->timestamp('commission_until')->nullable();
            $table->timestamps();

            $table->index('organization_id');
        });

        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_referral_id')->constrained()->cascadeOnDelete();
            // Satu pembayaran hanya menghasilkan satu komisi.
            $table->foreignId('subscription_payment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('invoice_number')->nullable(); // salinan, bertahan bila toko dihapus
            $table->string('payment_type', 40)->nullable();
            $table->decimal('base_amount', 12, 2);
            $table->decimal('percent', 5, 2);
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('earned'); // earned | paid | cancelled
            // dateTime, bukan timestamp: di MySQL lama kolom timestamp NOT NULL pertama otomatis
            // berubah ke waktu sekarang setiap baris diperbarui (mis. saat komisi ditandai dibayar).
            $table->dateTime('available_at'); // setelah masa tahan
            $table->timestamp('paid_at')->nullable();
            $table->string('paid_note', 500)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['affiliate_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_commissions');
        Schema::dropIfExists('affiliate_referrals');
        Schema::dropIfExists('affiliate_clicks');
        Schema::dropIfExists('affiliates');
    }
};
