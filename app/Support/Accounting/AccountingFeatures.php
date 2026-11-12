<?php

namespace App\Support\Accounting;

use App\Models\Store;

/**
 * Helper pusat untuk saklar Modul Akuntansi (Jurnal) per toko.
 *
 * Semua titik posting jurnal dan kunci periode POS WAJIB melewati helper ini.
 * Jangan cek `$store->settings['accounting_enabled']` tersebar tanpa helper.
 */
class AccountingFeatures
{
    /**
     * Apakah modul akuntansi aktif untuk toko ini?
     *
     * Mati = tidak ada posting jurnal otomatis, tidak ada kunci periode di POS,
     * dan laporan akuntansi disembunyikan (404 / redirect).
     */
    public static function enabled(?Store $store): bool
    {
        if (! $store) {
            return false;
        }

        // Paket Gratis: modul dikunci, kecuali toko yang jurnalnya sudah berjalan (PlanModules).
        return \App\Support\PlanModules::settingOn($store, 'accounting_enabled');
    }
}
