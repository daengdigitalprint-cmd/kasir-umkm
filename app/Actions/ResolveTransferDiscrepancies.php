<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\User;
use App\Support\InventoryCosting;
use App\Support\ProductQuantity;
use App\Support\TransferStockOperations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F8 — Penyelesaian selisih penerimaan oleh lokasi pengirim.
 *
 * Saat dikirim, stok pengirim sudah berkurang sebesar jumlah kirim. Bila penerima mencatat
 * selisih, bagian yang tidak masuk stok penerima "menggantung" sampai pengirim memutuskan:
 *
 * - Kurang/rusak → `returned` (barang kembali ke stok pengirim) atau `loss` (kerugian, stok tidak kembali).
 *   Bisa ditambah kiriman susulan: draft transfer baru berisi jumlah yang kurang.
 * - Lebih → `accepted` (stok pengirim berkurang, stok penerima bertambah) atau
 *   `rejected` (barang dikembalikan fisik, stok tidak berubah).
 */
final class ResolveTransferDiscrepancies
{
    /**
     * @param  array<int, array{resolution: string, note?: string|null, resend?: bool}>  $decisions  per ID baris
     * @return array{resolved: int, followup: StockTransfer|null}
     */
    public function handle(StockTransfer $transfer, User $user, array $decisions): array
    {
        return DB::transaction(function () use ($transfer, $user, $decisions) {
            $transfer = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if (! $transfer->isReceived()) {
                throw ValidationException::withMessages(['lines' => 'Selisih baru bisa diselesaikan setelah transfer diterima.']);
            }

            $lines = $transfer->lines()->whereIn('id', array_keys($decisions))->get()->keyBy('id');
            if ($lines->count() !== count($decisions)) {
                throw ValidationException::withMessages(['lines' => 'Ada baris yang bukan bagian dari transfer ini.']);
            }

            // Kunci produk asal & tujuan secara berurutan supaya tidak saling mengunci dengan transaksi lain.
            $productIds = $lines->pluck('from_product_id')->merge($lines->pluck('to_product_id'))->unique()->sort()->values();
            $products = Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $fromStore = $transfer->fromStore;
            $toStore = $transfer->toStore;
            $resolved = 0;
            $resend = [];

            foreach ($decisions as $lineId => $decision) {
                /** @var StockTransferLine $line */
                $line = $lines->get($lineId);
                $line->setRelation('transfer', $transfer);
                $fromProduct = $products->get($line->from_product_id);
                $toProduct = $products->get($line->to_product_id);
                $name = $fromProduct?->name ?? $toProduct?->name ?? 'barang';

                if (! $line->needsResolution()) {
                    throw ValidationException::withMessages(['lines' => "Selisih «{$name}» sudah diselesaikan atau tidak ada."]);
                }
                if (! $fromProduct || ! $toProduct) {
                    throw ValidationException::withMessages(['lines' => "Produk «{$name}» sudah tidak ada, selisihnya tidak bisa diproses."]);
                }

                $isOver = $line->discrepancy_type === StockTransferLine::DISCREPANCY_OVER;
                $resolution = (string) ($decision['resolution'] ?? '');
                $allowed = $isOver
                    ? [StockTransferLine::RESOLUTION_ACCEPTED, StockTransferLine::RESOLUTION_REJECTED]
                    : [StockTransferLine::RESOLUTION_RETURNED, StockTransferLine::RESOLUTION_LOSS];
                if (! in_array($resolution, $allowed, true)) {
                    throw ValidationException::withMessages(['lines' => "Pilihan penyelesaian untuk «{$name}» tidak sesuai dengan jenis selisihnya."]);
                }

                $qty = ProductQuantity::normalizeStorageAmount($fromProduct, $line->discrepancyQuantity());
                if (ProductQuantity::cmp($qty, '0.0000') <= 0) {
                    throw ValidationException::withMessages(['lines' => "Jumlah selisih «{$name}» nol, tidak ada yang perlu diselesaikan."]);
                }
                $number = $transfer->transfer_number;

                if ($resolution === StockTransferLine::RESOLUTION_RETURNED) {
                    TransferStockOperations::restoreAtSource(
                        $fromStore, $user, $fromProduct, $qty, $line,
                        mb_substr("{$number} · selisih {$qty} dikembalikan ke stok pengirim", 0, 255),
                    );
                } elseif ($resolution === StockTransferLine::RESOLUTION_ACCEPTED) {
                    TransferStockOperations::deductAtSource(
                        $fromStore, $user, $fromProduct, $qty, $line,
                        mb_substr("{$number} · kelebihan kirim {$qty} diakui", 0, 255),
                    );
                    $this->addAtDestination($transfer, $user, $toProduct, $qty, $line,
                        mb_substr("{$number} · kelebihan {$qty} diakui pengirim", 0, 255));
                }

                $line->update([
                    'resolution' => $resolution,
                    'resolution_note' => ($note = trim((string) ($decision['note'] ?? ''))) !== '' ? mb_substr($note, 0, 255) : null,
                    'resolved_by' => $user->id,
                    'resolved_at' => now(),
                ]);
                $resolved++;

                if (! $isOver && ! empty($decision['resend'])) {
                    $resend[] = ['from' => $fromProduct, 'to' => $toProduct, 'qty' => $qty];
                }
            }

            $followup = $resend !== [] ? $this->createFollowup($transfer, $user, $resend) : null;

            return ['resolved' => $resolved, 'followup' => $followup];
        });
    }

    /** Tambah stok tujuan + rata-rata tertimbang, sama seperti saat transfer diterima. */
    private function addAtDestination(StockTransfer $transfer, User $user, Product $product, string $qty, StockTransferLine $line, string $notes): void
    {
        $unitCost = (string) ($line->unit_cost_snapshot ?? InventoryCosting::currentAverageCost($product));

        TransferStockOperations::addAtDestination($transfer->toStore, $user, $product, $qty, $line, $notes);

        $product->refresh();
        $newAverageCost = InventoryCosting::weightedAverage(
            bcsub((string) $product->stock_quantity, $qty, ProductQuantity::SCALE),
            InventoryCosting::currentAverageCost($product),
            $qty,
            $unitCost,
        );
        $product->update([
            'average_cost' => $newAverageCost,
            'cost_price' => round((float) $newAverageCost, 2),
        ]);
    }

    /**
     * Kiriman susulan: draft transfer baru untuk jumlah yang kurang. Stok baru berkurang saat draft itu dikirim.
     *
     * @param  list<array{from: Product, to: Product, qty: string}>  $items
     */
    private function createFollowup(StockTransfer $transfer, User $user, array $items): StockTransfer
    {
        $followup = StockTransfer::create([
            'from_store_id' => $transfer->from_store_id,
            'to_store_id' => $transfer->to_store_id,
            'user_id' => $user->id,
            'notes' => "Kiriman susulan untuk selisih {$transfer->transfer_number}.",
            'status' => StockTransfer::STATUS_DRAFT,
            'followup_of_id' => $transfer->id,
        ]);

        foreach ($items as $item) {
            $from = $item['from']->fresh();
            $unitCost = InventoryCosting::currentAverageCost($from);
            StockTransferLine::create([
                'stock_transfer_id' => $followup->id,
                'from_product_id' => $from->id,
                'to_product_id' => $item['to']->id,
                'quantity' => $item['qty'],
                'base_unit' => $from->baseUnit(),
                'unit_cost_snapshot' => $unitCost,
                'total_cost_snapshot' => InventoryCosting::extendedCost($item['qty'], $unitCost, 2),
            ]);
        }

        return $followup;
    }
}
