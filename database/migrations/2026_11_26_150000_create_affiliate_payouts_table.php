<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pencairan komisi afiliasi: afiliator mengajukan penarikan, super admin
 * mentransfer lalu menandainya dibayar (dengan bukti transfer) atau menolaknya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('requested'); // requested | paid | rejected
            // Salinan rekening saat diajukan, supaya riwayat tidak berubah bila rekening diganti.
            $table->string('payout_provider', 60)->nullable();
            $table->string('payout_account_number', 40)->nullable();
            $table->string('payout_account_holder', 100)->nullable();
            $table->string('proof_path')->nullable(); // bukti transfer (disk public)
            $table->string('note', 500)->nullable();
            $table->string('reject_reason', 500)->nullable();
            // null = dicatat langsung oleh super admin tanpa permintaan dari afiliator.
            $table->dateTime('requested_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['affiliate_id', 'status']);
            $table->index('status');
        });

        Schema::table('affiliate_commissions', function (Blueprint $table) {
            // Komisi yang sedang/ sudah dicairkan menunjuk ke pencairannya.
            $table->foreignId('affiliate_payout_id')->nullable()->after('status')
                ->constrained('affiliate_payouts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_commissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('affiliate_payout_id');
        });

        Schema::dropIfExists('affiliate_payouts');
    }
};
