<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Program afiliasi tahap 3: aturan bisa diatur super admin, notifikasi untuk
 * afiliator, dan komisi bisa ditukar jadi perpanjangan langganan toko sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Satu baris; kolom null = pakai nilai bawaan di config/affiliate.php.
        Schema::create('affiliate_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->unsignedSmallInteger('commission_months')->nullable();
            $table->unsignedSmallInteger('cookie_days')->nullable();
            $table->unsignedSmallInteger('hold_days')->nullable();
            $table->unsignedInteger('min_payout')->nullable();
            $table->unsignedSmallInteger('referred_trial_days')->nullable();
            $table->timestamps();
        });

        Schema::create('affiliate_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title');
            $table->string('body', 500)->nullable();
            $table->dateTime('read_at')->nullable();
            $table->timestamps();

            $table->index(['affiliate_id', 'read_at']);
        });

        Schema::table('affiliate_payouts', function (Blueprint $table) {
            // transfer = ke rekening; subscription = ditukar jadi langganan toko afiliator sendiri.
            $table->string('type', 20)->default('transfer')->after('status');
            $table->foreignId('subscription_payment_id')->nullable()->after('type')
                ->constrained('subscription_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_payment_id');
            $table->dropColumn('type');
        });

        Schema::dropIfExists('affiliate_notifications');
        Schema::dropIfExists('affiliate_settings');
    }
};
