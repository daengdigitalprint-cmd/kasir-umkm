<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Pusat definisi key & TTL cache agar konsisten di seluruh aplikasi.
 *
 * Prinsip caching (sesuai kebutuhan POS):
 * - Transaksi kasir & stok barang TIDAK di-cache (harus realtime).
 * - Laporan dashboard / KPI BOLEH di-cache dengan TTL pendek (5 menit).
 * - Saat data berubah, observer memanggil forget() agar cache langsung segar.
 */
class CacheKeys
{
    /** TTL default laporan dashboard (5 menit — cukup pendek agar tidak basi). */
    public const REPORT_TTL_SECONDS = 300;

    /** TTL daftar master (produk/kategori) — jarang berubah, tapi tetap dibatasi. */
    public const MASTER_TTL_SECONDS = 600;

    public static function dashboardInsights(int $storeId): string
    {
        return "dashboard.insights.{$storeId}";
    }

    /**
     * Key tag grup laporan store. Dipakai version-bump supaya semua key periode
     * laporan store ter-invalidasi sekaligus — bekerja di semua cache store.
     */
    public static function storeReportVersion(int $storeId): string
    {
        return "store.{$storeId}.reports.version";
    }

    public static function kpiMonthly(int $storeId, string $period): string
    {
        return "kpi.monthly.{$storeId}.{$period}.v".self::currentReportVersion($storeId);
    }

    public static function kpiTopProducts(int $storeId, string $period, int $limit): string
    {
        return "kpi.top_products.{$storeId}.{$period}.{$limit}.v".self::currentReportVersion($storeId);
    }

    public static function productCategories(int $storeId): string
    {
        return "products.categories.{$storeId}";
    }

    /**
     * Invalidate semua cache laporan store (dashboard insights, KPI semua periode).
     * Dipanggil observer Transaction saat transaksi dibuat/diubah/dihapus.
     */
    public static function forgetStoreReports(int $storeId): void
    {
        Cache::forget(self::dashboardInsights($storeId));
        Cache::increment(self::storeReportVersion($storeId));
    }

    /** Invalidate cache master produk/kategori milik store. */
    public static function forgetStoreMasters(int $storeId): void
    {
        Cache::forget(self::productCategories($storeId));
    }

    private static function currentReportVersion(int $storeId): int
    {
        return (int) Cache::get(self::storeReportVersion($storeId), 0);
    }
}
