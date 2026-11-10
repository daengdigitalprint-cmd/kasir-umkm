<?php

namespace App\Support\Accounting;

use App\Models\AccountingPeriod;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class AccountingPeriodGuard
{
    public static function assertDateOpen(Store $store, CarbonInterface|string $date, string $errorKey = 'entry_date'): void
    {
        if (! AccountingFeatures::enabled($store)) {
            return;
        }

        $dateStr = is_string($date) ? $date : $date->toDateString();

        // 1. Cek periode ditutup
        $period = AccountingPeriod::query()
            ->where('store_id', $store->id)
            ->where('status', AccountingPeriod::STATUS_CLOSED)
            ->whereDate('start_date', '<=', $dateStr)
            ->whereDate('end_date', '>=', $dateStr)
            ->first();

        if ($period) {
            throw ValidationException::withMessages([
                $errorKey => "Periode {$period->name} sudah ditutup. Buat jurnal pembalik pada periode terbuka.",
            ]);
        }

        // 2. Cek limit backdate (Anti tanggal mundur berlebihan H-7)
        $maxBackdateDays = (int) ($store->settings['max_backdate_days'] ?? 7);
        $carbonDate = is_string($date) ? \Carbon\Carbon::parse($date)->startOfDay() : $date->copy()->startOfDay();

        if ($carbonDate->isBefore(now()->startOfDay()->subDays($maxBackdateDays))) {
            throw ValidationException::withMessages([
                $errorKey => "Tanggal transaksi terlalu lama di masa lalu (Maksimal mundur {$maxBackdateDays} hari). Hal ini untuk menjaga stabilitas laporan stok HPP.",
            ]);
        }
    }

    /**
     * Pesan pengunci POS bila tanggal jual tidak boleh diposting ke jurnal.
     * Null = aman (jurnal bisa dicatat dengan tanggal yang sama).
     *
     * Toko yang belum pernah membuat periode tidak dikunci, agar setup awal
     * tidak memblokir kasir. Setelah ada periode, penjualan hanya boleh
     * jika tanggal itu tidak tertutup dan ada periode terbuka yang mencakupnya.
     */
    public static function posLockMessage(Store $store, CarbonInterface|string $date): ?string
    {
        // Modul mati = POS tidak pernah terkunci oleh periode akuntansi.
        if (! AccountingFeatures::enabled($store)) {
            return null;
        }

        $dateStr = is_string($date) ? $date : $date->toDateString();
        $carbonDate = is_string($date) ? \Carbon\Carbon::parse($date)->startOfDay() : $date->copy()->startOfDay();

        // 1. Cek limit backdate
        $maxBackdateDays = (int) ($store->settings['max_backdate_days'] ?? 7);
        if ($carbonDate->isBefore(now()->startOfDay()->subDays($maxBackdateDays))) {
            return "Tanggal transaksi terlalu lama di masa lalu (maksimal mundur {$maxBackdateDays} hari). Pembuatan transaksi ditolak untuk menjaga stabilitas HPP.";
        }

        // 2. Cek periode ditutup
        $closed = AccountingPeriod::query()
            ->where('store_id', $store->id)
            ->where('status', AccountingPeriod::STATUS_CLOSED)
            ->whereDate('start_date', '<=', $dateStr)
            ->whereDate('end_date', '>=', $dateStr)
            ->orderByDesc('end_date')
            ->first();

        if ($closed) {
            return "Periode \"{$closed->name}\" sudah ditutup. Buat periode terbuka untuk bulan berjalan (tanggal 1 sampai akhir bulan), lalu ulangi pembayaran. Transaksi tidak diproses agar penjualan tetap sinkron dengan jurnal.";
        }

        $usesPeriods = AccountingPeriod::query()->where('store_id', $store->id)->exists();
        if (! $usesPeriods) {
            return null;
        }

        // 3. Cek wajib periode terbuka
        $open = AccountingPeriod::query()
            ->where('store_id', $store->id)
            ->where('status', AccountingPeriod::STATUS_OPEN)
            ->whereDate('start_date', '<=', $dateStr)
            ->whereDate('end_date', '>=', $dateStr)
            ->first();

        if (! $open) {
            return 'Belum ada periode akuntansi terbuka untuk tanggal ini. Buat periode bulan berjalan (tanggal 1 sampai akhir bulan) dengan status terbuka, lalu ulangi pembayaran.';
        }

        return null;
    }

    /**
     * Periode lampau (end_date < awal bulan berjalan) yang masih berstatus
     * terbuka. Dipakai untuk banner pengingat "bulan lama belum ditutup"
     * di Backoffice agar owner tidak lupa tutup buku.
     *
     * @return \Illuminate\Support\Collection<int, AccountingPeriod>
     */
    public static function unclosedPastPeriods(Store $store): \Illuminate\Support\Collection
    {
        if (! AccountingFeatures::enabled($store)) {
            return collect();
        }

        return AccountingPeriod::query()
            ->where('store_id', $store->id)
            ->where('status', AccountingPeriod::STATUS_OPEN)
            ->whereDate('end_date', '<', now()->startOfMonth()->toDateString())
            ->orderBy('end_date')
            ->get();
    }
}
