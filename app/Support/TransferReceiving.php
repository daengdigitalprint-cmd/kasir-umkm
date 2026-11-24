<?php

namespace App\Support;

use App\Models\StockTransfer;
use App\Models\Store;

/**
 * F9 — Aturan penerimaan transfer stok per lokasi penerima.
 */
final class TransferReceiving
{
    /** Batas waktu penerima mengajukan koreksi setelah menandatangani penerimaan. */
    public const CORRECTION_WINDOW_HOURS = 24;

    /**
     * Penerimaan buta: penerima menghitung dulu tanpa melihat jumlah kirim,
     * baru dicocokkan. Diatur per lokasi penerima (stores.settings).
     */
    public static function blindEnabled(?Store $store): bool
    {
        return (bool) ($store?->settings['blind_transfer_receiving'] ?? false);
    }

    public static function correctionDeadline(StockTransfer $transfer): ?\Illuminate\Support\Carbon
    {
        return $transfer->received_at?->copy()->addHours(self::CORRECTION_WINDOW_HOURS);
    }

    public static function withinCorrectionWindow(StockTransfer $transfer): bool
    {
        $deadline = self::correctionDeadline($transfer);

        return $transfer->isReceived() && $deadline !== null && now()->lessThanOrEqualTo($deadline);
    }
}
