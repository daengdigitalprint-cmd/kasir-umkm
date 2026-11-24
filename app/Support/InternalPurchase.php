<?php

namespace App\Support;

use App\Models\Product;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\Store;

/**
 * Jual-beli internal: outlet membeli stok ke gudang/CK dalam satu grup usaha.
 *
 * Berbeda dari transfer stok biasa (hanya memindahkan barang), permintaan berjenis
 * "beli" punya harga per barang, total tagihan, dan status pembayaran. Bagi gudang
 * ini pemasukan, bagi outlet ini pengeluaran pembelian stok.
 */
final class InternalPurchase
{
    public const MODE_TRANSFER = 'transfer';

    public const MODE_PURCHASE = 'purchase';

    public const MODE_LABELS = [
        self::MODE_TRANSFER => 'Transfer stok',
        self::MODE_PURCHASE => 'Beli ke gudang',
    ];

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_LABELS = [
        self::PAYMENT_UNPAID => 'Belum dibayar',
        self::PAYMENT_PAID => 'Lunas',
    ];

    public const PAYMENT_METHODS = [
        'cash' => 'Tunai',
        'transfer' => 'Transfer bank',
        'offset' => 'Potong setoran',
        'other' => 'Lainnya',
    ];

    /** Status permintaan yang sudah menjadi transaksi (barang dikirim / diterima). */
    public const BILLABLE_STATUSES = [StockRequest::STATUS_APPROVED, StockRequest::STATUS_COMPLETED];

    public static function normalizeMode(mixed $mode): string
    {
        return $mode === self::MODE_PURCHASE ? self::MODE_PURCHASE : self::MODE_TRANSFER;
    }

    /** Jenis bawaan untuk permintaan baru dari sebuah lokasi: jenis yang terakhir dipakainya. */
    public static function defaultMode(Store $requester): string
    {
        return self::normalizeMode($requester->settings['stock_request_default_mode'] ?? null);
    }

    public static function rememberMode(Store $requester, string $mode): void
    {
        $mode = self::normalizeMode($mode);
        if (self::defaultMode($requester) === $mode) {
            return;
        }

        $settings = $requester->settings ?? [];
        $settings['stock_request_default_mode'] = $mode;
        $requester->update(['settings' => $settings]);
    }

    /** Markup harga jual gudang di atas HPP-nya, dalam persen (0 = dijual seharga HPP). */
    public static function markupPercent(Store $supplier): float
    {
        return max(0.0, min(1000.0, (float) ($supplier->settings['internal_sale_markup_percent'] ?? 0)));
    }

    /**
     * Harga jual bawaan gudang untuk satu barang (per satuan dasar): HPP rata-rata
     * gudang ditambah markup, dibulatkan ke rupiah. Gudang masih bisa mengubahnya
     * per baris saat menyetujui.
     */
    public static function defaultUnitPrice(Product $supplierProduct, Store $supplier): float
    {
        $cost = (float) InventoryCosting::currentAverageCost($supplierProduct);

        return (float) round($cost * (1 + self::markupPercent($supplier) / 100), 0);
    }

    /**
     * Hitung ulang tagihan dari jumlah yang benar-benar diterima outlet pada transfernya.
     * Dipanggil saat transfer diterima: barang kurang/rusak tidak ikut ditagih.
     */
    public static function billFromTransfer(StockRequest $request, StockTransfer $transfer): void
    {
        if ($request->mode !== self::MODE_PURCHASE) {
            return;
        }

        $received = $transfer->lines()->get(['from_product_id', 'quantity', 'quantity_received'])
            ->keyBy('from_product_id');

        $total = '0.00';
        foreach ($request->lines()->get() as $line) {
            $transferLine = $line->supplier_product_id ? $received->get($line->supplier_product_id) : null;
            if (! $transferLine || $line->unit_price === null) {
                continue;
            }

            $quantity = (string) ($transferLine->quantity_received ?? $transferLine->quantity);
            $lineTotal = bcmul($quantity, (string) $line->unit_price, 2);
            $line->update(['quantity_billed' => $quantity, 'line_total' => $lineTotal]);
            $total = bcadd($total, $lineTotal, 2);
        }

        $request->update(['total_amount' => $total]);
    }
}
