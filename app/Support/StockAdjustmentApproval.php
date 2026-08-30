<?php

namespace App\Support;

use App\Models\StockAdjustment;
use App\Models\Store;
use App\Models\User;

/**
 * Aturan persetujuan dokumen koreksi stok per toko.
 * Disimpan di kolom stores.settings['stock_adjustment'] agar tidak perlu tabel terpisah.
 */
final class StockAdjustmentApproval
{
    public const DEFAULTS = [
        // Persetujuan wajib bila nilai mutlak selisih melampaui ambang ini (0 = selalu wajib).
        'approval_threshold' => 0,
        // Aktifkan alur persetujuan. Nonaktif berarti dokumen langsung dapat diposting.
        'approval_enabled' => false,
        // Larang pembuat dokumen menyetujui dokumennya sendiri.
        'segregation_of_duties' => true,
        // Izinkan edit stok langsung di form Edit Produk (menyembunyikan menu koreksi stok).
        'direct_stock_edit' => false,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function all(?Store $store): array
    {
        $raw = is_array($store?->settings['stock_adjustment'] ?? null)
            ? $store->settings['stock_adjustment']
            : [];

        return array_merge(self::DEFAULTS, array_intersect_key($raw, self::DEFAULTS));
    }

    public static function enabled(?Store $store): bool
    {
        return (bool) self::all($store)['approval_enabled'];
    }

    public static function threshold(?Store $store): float
    {
        return max(0.0, (float) self::all($store)['approval_threshold']);
    }

    public static function segregationOfDuties(?Store $store): bool
    {
        return (bool) self::all($store)['segregation_of_duties'];
    }

    public static function directStockEdit(?Store $store): bool
    {
        return (bool) self::all($store)['direct_stock_edit'];
    }

    /**
     * Dokumen memerlukan persetujuan bila alur aktif dan nilai selisihnya
     * melampaui ambang. Ambang nol berarti seluruh dokumen wajib disetujui.
     */
    public static function requiresApproval(?Store $store, StockAdjustment $document): bool
    {
        if (! self::enabled($store)) {
            return false;
        }

        $threshold = self::threshold($store);
        if ($threshold <= 0.0) {
            return true;
        }

        return abs((float) $document->total_variance_value) > $threshold;
    }

    /**
     * Penyetuju harus admin atau manajer, dan — bila pemisahan wewenang aktif —
     * bukan orang yang membuat dokumen tersebut.
     *
     * @throws \RuntimeException
     */
    public static function assertCanApprove(?Store $store, StockAdjustment $document, User $approver): void
    {
        if (! $approver->isAdmin() && ! $approver->isManager()) {
            throw new \RuntimeException('Hanya admin atau manajer yang dapat menyetujui dokumen koreksi stok.');
        }

        if (
            self::segregationOfDuties($store)
            && $document->created_by !== null
            && (int) $document->created_by === (int) $approver->id
        ) {
            throw new \RuntimeException(
                'Dokumen tidak dapat disetujui oleh pembuatnya sendiri. Mintalah pengguna lain untuk meninjau.'
            );
        }
    }

    /**
     * Simpan sebagian aturan ke stores.settings['stock_adjustment'].
     *
     * @param  array<string, mixed>  $values
     */
    public static function update(Store $store, array $values): void
    {
        $settings = is_array($store->settings) ? $store->settings : [];
        $current = array_merge(
            self::DEFAULTS,
            is_array($settings['stock_adjustment'] ?? null) ? $settings['stock_adjustment'] : []
        );

        foreach (array_keys(self::DEFAULTS) as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $current[$key] = match ($key) {
                'approval_threshold' => max(0.0, (float) $values[$key]),
                default => (bool) $values[$key],
            };
        }

        $settings['stock_adjustment'] = $current;
        $store->update(['settings' => $settings]);
    }
}
