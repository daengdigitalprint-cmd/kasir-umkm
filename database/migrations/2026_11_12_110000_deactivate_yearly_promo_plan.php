<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nonaktifkan paket "Promo tahunan (12 bulan bayar 8)": harganya (Rp1.188.000)
 * lebih mahal daripada GrowUp tahunan biasa (Rp990.000), jadi tidak lagi ditawarkan.
 * Baris paket tidak dihapus agar riwayat tetap utuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscription_plans')
            ->where('slug', 'promo-onboarding-tahunan')
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('subscription_plans')
            ->where('slug', 'promo-onboarding-tahunan')
            ->update(['is_active' => true, 'updated_at' => now()]);
    }
};
