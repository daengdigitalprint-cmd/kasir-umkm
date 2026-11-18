<?php

use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockOpnameImport;
use App\Support\InventoryType;
use App\Support\OpnameCounting;
use Illuminate\Database\Migrations\Migration;

/**
 * Sesi opname yang masih draft disesuaikan dengan aturan baru:
 * - Produk hasil produksi tidak ikut dihitung → barisnya dibuang dari sesi draft.
 * - Hasil Excel dihitung terhadap waktu unggah (bukan waktu sesi dibuka), agar
 *   penjualan selama sesi terbuka tidak terhitung dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        $drafts = StockAdjustment::query()
            ->where('type', StockAdjustment::TYPE_OPNAME)
            ->where('status', StockAdjustment::STATUS_DRAFT)
            ->get();

        foreach ($drafts as $document) {
            StockAdjustmentLine::query()
                ->where('stock_adjustment_id', $document->id)
                ->whereHas('product', fn ($q) => $q->where('inventory_type', InventoryType::PRODUCTION_OUTPUT))
                ->delete();

            $uploadedAt = StockOpnameImport::query()
                ->where('stock_adjustment_id', $document->id)
                ->whereNotNull('applied_at')
                ->latest('id')
                ->value('created_at');

            if ($uploadedAt !== null) {
                $lines = StockAdjustmentLine::query()
                    ->where('stock_adjustment_id', $document->id)
                    ->whereNotNull('counted_quantity')
                    ->whereNull('counted_at')
                    ->get();

                foreach ($lines as $line) {
                    OpnameCounting::record($document, $line, (string) $line->counted_quantity, \Illuminate\Support\Carbon::parse($uploadedAt));
                }
            }

            $document->update(['total_variance_value' => (string) $document->lines()->sum('variance_value')]);
        }
    }

    public function down(): void
    {
        // Baris yang dibuang tidak dikembalikan; sesi draft bisa dibuka ulang.
    }
};
