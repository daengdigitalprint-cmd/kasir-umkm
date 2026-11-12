<?php

use App\Support\StorePlanLimits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Samakan data paket dengan yang dijanjikan di landing & halaman Langganan:
 * - Paket Gratis (Starter): batas 50 transaksi/bulan dihapus, daftar fitur diperjelas.
 * - GrowUp tahunan: Rp990.000 (hemat 2 bulan dari 12 x Rp99.000).
 * - Teks "Hingga 5 Outlet" pada paket tahunan dibetulkan menjadi 3 (sesuai max_stores).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscription_plans')
            ->whereIn('slug', ['starter', 'starter-tahunan'])
            ->update([
                'max_transactions' => null,
                'features' => json_encode(StorePlanLimits::STARTER_FEATURES, JSON_UNESCAPED_UNICODE),
                'description' => 'Untuk coba dan toko kecil — gratis, tanpa batas masa aktif',
                'updated_at' => now(),
            ]);

        DB::table('subscription_plans')
            ->where('slug', 'bisnis-tahunan')
            ->update(['price' => 990000, 'updated_at' => now()]);

        $plans = DB::table('subscription_plans')
            ->where('features', 'like', '%Hingga 5 Outlet%')
            ->where('max_stores', 3)
            ->get(['id', 'features']);

        foreach ($plans as $plan) {
            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'features' => str_replace('Hingga 5 Outlet', 'Hingga 3 Outlet', (string) $plan->features),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Perubahan data harga/batas tidak dikembalikan otomatis.
    }
};
