<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferCorrection;
use App\Models\StockTransferLine;
use App\Models\User;
use App\Support\InventoryCosting;
use App\Support\ProductQuantity;
use App\Support\TransferReceiving;
use App\Support\TransferStockOperations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F9 — Koreksi jumlah diterima setelah penerimaan ditandatangani.
 *
 * Penerima mengajukan jumlah tiba yang benar per barang (dalam batas waktu); pengirim memutuskan.
 * Saat disetujui, stok penerima disesuaikan sebesar selisihnya dan status selisih baris dihitung ulang,
 * sehingga selisih baru kembali menunggu keputusan pengirim lewat alur penyelesaian selisih (F8).
 *
 * Baris yang selisihnya sudah diselesaikan dengan perpindahan stok (dikembalikan ke pengirim /
 * kelebihan diakui) dikunci: koreksinya harus lewat Penyesuaian Stok atau transfer baru.
 */
final class TransferReceiptCorrections
{
    /**
     * @param  list<array{id: int, quantity_received: mixed, discrepancy_type?: string|null, note?: string|null}>  $rows
     */
    public function request(StockTransfer $transfer, User $user, string $reason, array $rows): StockTransferCorrection
    {
        return DB::transaction(function () use ($transfer, $user, $reason, $rows) {
            $transfer = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if (! $transfer->isReceived()) {
                throw ValidationException::withMessages(['lines' => 'Koreksi baru bisa diajukan setelah transfer diterima.']);
            }
            if (! TransferReceiving::withinCorrectionWindow($transfer)) {
                throw ValidationException::withMessages([
                    'lines' => 'Batas waktu pengajuan koreksi ('.TransferReceiving::CORRECTION_WINDOW_HOURS.' jam setelah diterima) sudah lewat. Gunakan Penyesuaian Stok.',
                ]);
            }
            if ($transfer->corrections()->where('status', StockTransferCorrection::STATUS_PENDING)->exists()) {
                throw ValidationException::withMessages(['lines' => 'Masih ada pengajuan koreksi yang menunggu keputusan pengirim.']);
            }

            $lines = $transfer->lines()->with('toProduct')->get()->keyBy('id');
            $toStore = $transfer->toStore;
            $payload = [];

            foreach ($rows as $row) {
                /** @var StockTransferLine|null $line */
                $line = $lines->get((int) $row['id']);
                if (! $line || ! $line->toProduct) {
                    throw ValidationException::withMessages(['lines' => 'Ada baris yang bukan bagian dari transfer ini.']);
                }
                $product = $line->toProduct;
                $this->assertCorrectable($transfer, $line, $product->name);
                if (TransferStockOperations::hasBatchAllocations($toStore, $line)) {
                    throw ValidationException::withMessages([
                        'lines' => "«{$product->name}» memakai batch/ED, jadi jumlah diterimanya tidak bisa dikoreksi di sini. Gunakan Penyesuaian Stok.",
                    ]);
                }

                $before = ProductQuantity::normalizeStorageAmount($product, $line->arrivedQuantity());
                $after = ProductQuantity::normalizeStorageAmount($product, $row['quantity_received']);
                if (ProductQuantity::cmp($before, $after) === 0) {
                    continue; // tidak berubah
                }

                $isShort = ProductQuantity::cmp($after, (string) $line->quantity) < 0;
                $payload[] = [
                    'stock_transfer_line_id' => $line->id,
                    'quantity_before' => $before,
                    'quantity_after' => $after,
                    'discrepancy_type' => $isShort
                        ? (($row['discrepancy_type'] ?? null) ?: StockTransferLine::DISCREPANCY_SHORT)
                        : null,
                    'note' => ($note = trim((string) ($row['note'] ?? ''))) !== '' ? mb_substr($note, 0, 255) : null,
                ];
            }

            if ($payload === []) {
                throw ValidationException::withMessages(['lines' => 'Ubah jumlah diterima minimal satu barang.']);
            }

            $correction = StockTransferCorrection::create([
                'stock_transfer_id' => $transfer->id,
                'status' => StockTransferCorrection::STATUS_PENDING,
                'reason' => mb_substr(trim($reason), 0, 500),
                'requested_by' => $user->id,
            ]);
            $correction->lines()->createMany($payload);

            return $correction;
        });
    }

    public function approve(StockTransferCorrection $correction, User $user, ?string $note = null): StockTransferCorrection
    {
        return DB::transaction(function () use ($correction, $user, $note) {
            $transfer = StockTransfer::query()->whereKey($correction->stock_transfer_id)->lockForUpdate()->firstOrFail();
            $correction = StockTransferCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($correction);

            $correctionLines = $correction->lines()->with('transferLine')->get();
            $toIds = $correctionLines->pluck('transferLine.to_product_id')->filter()->unique()->sort()->values();
            $products = Product::query()->whereIn('id', $toIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $toStore = $transfer->toStore;

            foreach ($correctionLines as $cLine) {
                /** @var StockTransferLine|null $line */
                $line = $cLine->transferLine;
                $product = $line ? $products->get($line->to_product_id) : null;
                if (! $line || ! $product) {
                    throw ValidationException::withMessages(['correction' => 'Barang pada pengajuan ini sudah tidak ada.']);
                }
                $line->setRelation('transfer', $transfer);
                $this->assertCorrectable($transfer, $line, $product->name);

                $before = ProductQuantity::normalizeStorageAmount($product, (string) $cLine->quantity_before);
                $current = ProductQuantity::normalizeStorageAmount($product, $line->arrivedQuantity());
                if (ProductQuantity::cmp($before, $current) !== 0) {
                    throw ValidationException::withMessages([
                        'correction' => "Catatan «{$product->name}» sudah berubah sejak koreksi diajukan. Tolak pengajuan ini dan minta diajukan ulang.",
                    ]);
                }

                $sent = (string) $line->quantity;
                $after = ProductQuantity::normalizeStorageAmount($product, (string) $cLine->quantity_after);
                $oldIn = ProductQuantity::normalizeStorageAmount($product, (string) ($line->quantity_received ?? $line->quantity));
                $newIn = ProductQuantity::cmp($after, $sent) > 0 ? ProductQuantity::normalizeStorageAmount($product, $sent) : $after;
                $number = $transfer->transfer_number;

                // Stok penerima mengikuti jumlah yang benar-benar tiba (maksimal sebesar jumlah kirim).
                $cmp = ProductQuantity::cmp($newIn, $oldIn);
                if ($cmp > 0) {
                    $delta = ProductQuantity::sub($newIn, $oldIn);
                    $unitCost = (string) ($line->unit_cost_snapshot ?? InventoryCosting::currentAverageCost($product));
                    TransferStockOperations::addAtDestination($toStore, $user, $product, $delta, $line,
                        mb_substr("{$number} · koreksi penerimaan +{$delta}: {$correction->reason}", 0, 255));
                    $product->refresh();
                    $newAverageCost = InventoryCosting::weightedAverage(
                        bcsub((string) $product->stock_quantity, $delta, ProductQuantity::SCALE),
                        InventoryCosting::currentAverageCost($product),
                        $delta,
                        $unitCost,
                    );
                    $product->update(['average_cost' => $newAverageCost, 'cost_price' => round((float) $newAverageCost, 2)]);
                } elseif ($cmp < 0) {
                    $delta = ProductQuantity::sub($oldIn, $newIn);
                    TransferStockOperations::removeAtDestination($toStore, $user, $product, $delta, $line,
                        mb_substr("{$number} · koreksi penerimaan -{$delta}: {$correction->reason}", 0, 255));
                }

                // Hitung ulang status selisih baris; selisih baru kembali menunggu keputusan pengirim.
                $isShort = ProductQuantity::cmp($after, $sent) < 0;
                $isOver = ProductQuantity::cmp($after, $sent) > 0;
                $line->update([
                    'quantity_received' => $newIn,
                    'discrepancy_type' => $isOver
                        ? StockTransferLine::DISCREPANCY_OVER
                        : ($isShort ? ($cLine->discrepancy_type ?: StockTransferLine::DISCREPANCY_SHORT) : null),
                    'quantity_over' => $isOver ? ProductQuantity::sub($after, $sent) : null,
                    'discrepancy_reason' => ($isShort || $isOver)
                        ? mb_substr('Koreksi: '.($cLine->note ?: $correction->reason), 0, 255)
                        : null,
                    'resolution' => null,
                    'resolution_note' => null,
                    'resolved_by' => null,
                    'resolved_at' => null,
                ]);
            }

            $correction->update([
                'status' => StockTransferCorrection::STATUS_APPROVED,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_note' => ($note = trim((string) $note)) !== '' ? mb_substr($note, 0, 255) : null,
            ]);

            return $correction;
        });
    }

    public function reject(StockTransferCorrection $correction, User $user, string $note): StockTransferCorrection
    {
        return $this->close($correction, $user, StockTransferCorrection::STATUS_REJECTED, $note);
    }

    public function cancel(StockTransferCorrection $correction, User $user): StockTransferCorrection
    {
        return $this->close($correction, $user, StockTransferCorrection::STATUS_CANCELLED, null);
    }

    private function close(StockTransferCorrection $correction, User $user, string $status, ?string $note): StockTransferCorrection
    {
        return DB::transaction(function () use ($correction, $user, $status, $note) {
            $correction = StockTransferCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($correction);
            $correction->update([
                'status' => $status,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_note' => ($note = trim((string) $note)) !== '' ? mb_substr($note, 0, 255) : null,
            ]);

            return $correction;
        });
    }

    private function assertPending(StockTransferCorrection $correction): void
    {
        if (! $correction->isPending()) {
            throw ValidationException::withMessages(['correction' => 'Pengajuan koreksi ini sudah diputuskan.']);
        }
    }

    private function assertCorrectable(StockTransfer $transfer, StockTransferLine $line, string $name): void
    {
        if (! $transfer->isReceived()) {
            throw ValidationException::withMessages(['lines' => 'Koreksi hanya untuk transfer yang sudah diterima.']);
        }
        if ($line->isLockedForCorrection()) {
            throw ValidationException::withMessages([
                'lines' => "Selisih «{$name}» sudah diselesaikan dengan perpindahan stok, jadi tidak bisa dikoreksi di sini. Gunakan Penyesuaian Stok atau transfer baru.",
            ]);
        }
    }
}
