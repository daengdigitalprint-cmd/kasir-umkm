<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrasi Opsi B (dokumen rencana §6):
 * - Toko yang SUDAH memakai akuntansi (punya journal_entries ATAU accounting_periods)
 *   di-set settings.accounting_enabled = true agar perilakunya tidak berubah mendadak.
 * - Toko tanpa histori jurnal dibiarkan tanpa key (= false / mati).
 */
return new class extends Migration
{
    public function up(): void
    {
        $storeIds = DB::table('journal_entries')
            ->select('store_id')
            ->distinct()
            ->union(DB::table('accounting_periods')->select('store_id')->distinct())
            ->pluck('store_id');

        foreach ($storeIds as $storeId) {
            $settings = DB::table('stores')->where('id', $storeId)->value('settings');
            $settings = $settings ? json_decode($settings, true) : [];

            if (! is_array($settings)) {
                $settings = [];
            }

            if (! array_key_exists('accounting_enabled', $settings)) {
                $settings['accounting_enabled'] = true;

                DB::table('stores')->where('id', $storeId)->update([
                    'settings' => json_encode($settings),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Tidak bisa dibalik dengan aman: toko yang menyalakan modul manual
        // setelah migrasi tidak bisa dibedakan dari hasil migrasi ini.
    }
};
