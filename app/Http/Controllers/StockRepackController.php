<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Support\AdjustmentStockOperations;
use App\Support\CostVisibility;
use App\Support\InventoryCosting;
use App\Support\ProductQuantity;
use App\Support\StorePlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * Pecah kemasan / repack: mis. 1 KG kacang arab dipecah menjadi 72 bungkus 15 gr.
 *
 * Produk asal berkurang, produk hasil bertambah, dan nilai persediaan berpindah utuh:
 * HPP per satuan hasil = (jumlah asal × HPP asal) ÷ jumlah hasil. Karena nilainya
 * hanya berpindah antar produk, dokumen ini tidak membuat jurnal untung/rugi.
 */
class StockRepackController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $store = $request->user()->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $products = $this->repackableQuery($store)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'barcode', 'unit', 'stock_quantity', 'average_cost', 'cost_price', 'allows_decimal']);

        return CostVisibility::render('StockAdjustments/Repack', [
            'products' => $products,
            'recent' => $this->recentPairs($store->id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        StorePlanLimits::assertAdvancedRetail($store, 'Fitur pecah kemasan tersedia mulai paket GrowUp.');

        $validated = $request->validate([
            'source_product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('store_id', $store->id)],
            'source_quantity' => ['required', 'numeric', 'gt:0'],
            'output_product_id' => ['required', 'integer', 'different:source_product_id', Rule::exists('products', 'id')->where('store_id', $store->id)],
            'output_quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'output_product_id.different' => 'Produk hasil harus berbeda dari produk asal.',
            'source_quantity.gt' => 'Isi jumlah yang dipecah.',
            'output_quantity.gt' => 'Isi jumlah hasil.',
        ]);

        try {
            $document = DB::transaction(function () use ($user, $store, $validated) {
                $ids = collect([(int) $validated['source_product_id'], (int) $validated['output_product_id']])->sort()->values();
                $products = Product::query()
                    ->where('store_id', $store->id)
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $source = $products->get((int) $validated['source_product_id']);
                $output = $products->get((int) $validated['output_product_id']);

                foreach ([$source, $output] as $product) {
                    AdjustmentStockOperations::assertAdjustable($store, $product);
                }

                $sourceQty = ProductQuantity::normalizeStorageAmount($source, $validated['source_quantity']);
                $outputQty = ProductQuantity::normalizeStorageAmount($output, $validated['output_quantity']);
                $sourceStock = AdjustmentStockOperations::currentQuantity($source);
                $outputStock = AdjustmentStockOperations::currentQuantity($output);

                if (bccomp($sourceQty, $sourceStock, ProductQuantity::SCALE) > 0) {
                    throw ValidationException::withMessages([
                        'source_quantity' => "Stok «{$source->name}» hanya ".(float) $sourceStock.($source->unit ? ' '.$source->unit : '').'.',
                    ]);
                }

                // Nilai yang berpindah dari produk asal ke produk hasil.
                $sourceCost = InventoryCosting::currentAverageCost($source);
                $transferTotal = bcmul($sourceQty, $sourceCost, 6);
                $outputUnitCost = InventoryCosting::unitCostFromTotal($transferTotal, $outputQty);
                $transferValue = InventoryCosting::extendedCost($sourceQty, $sourceCost);
                $outputAverage = InventoryCosting::averageCostAfterInbound($output, $outputQty, $outputUnitCost);

                $document = StockAdjustment::create([
                    'store_id' => $store->id,
                    'type' => StockAdjustment::TYPE_REPACK,
                    'reason_code' => StockAdjustment::REASON_REPACK,
                    'status' => StockAdjustment::STATUS_DRAFT,
                    'notes' => $validated['notes'] ?? null,
                    'counted_at' => now(),
                    'created_by' => $user->id,
                ]);

                $sourceLine = StockAdjustmentLine::create([
                    'stock_adjustment_id' => $document->id,
                    'product_id' => $source->id,
                    'system_quantity' => $sourceStock,
                    'counted_quantity' => bcsub($sourceStock, $sourceQty, ProductQuantity::SCALE),
                    'variance_quantity' => bcmul($sourceQty, '-1', ProductQuantity::SCALE),
                    'unit_cost' => $sourceCost,
                    'variance_value' => bcmul($transferValue, '-1', 2),
                    'line_notes' => 'Dipecah',
                ]);

                $outputLine = StockAdjustmentLine::create([
                    'stock_adjustment_id' => $document->id,
                    'product_id' => $output->id,
                    'system_quantity' => $outputStock,
                    'counted_quantity' => bcadd($outputStock, $outputQty, ProductQuantity::SCALE),
                    'variance_quantity' => $outputQty,
                    'unit_cost' => $outputUnitCost,
                    'variance_value' => $transferValue,
                    'line_notes' => 'Hasil pecah kemasan',
                ]);

                AdjustmentStockOperations::applyLine($store, $user, $source, $document, $sourceLine);
                AdjustmentStockOperations::applyLine($store, $user, $output, $document, $outputLine);

                // applyLine menilai baris dengan HPP berjalan; untuk repack nilainya dipindahkan utuh.
                $sourceLine->update(['unit_cost' => $sourceCost, 'variance_value' => bcmul($transferValue, '-1', 2)]);
                $outputLine->update(['unit_cost' => $outputUnitCost, 'variance_value' => $transferValue]);
                $output->refresh()->update(['average_cost' => $outputAverage]);

                $document->update([
                    'status' => StockAdjustment::STATUS_POSTED,
                    'posted_by' => $user->id,
                    'posted_at' => now(),
                    'total_variance_value' => '0.00',
                ]);

                return $document->fresh();
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['source_quantity' => $e->getMessage()]);
        }

        return redirect()->route('stock-adjustments.show', $document)
            ->with('success', "Pecah kemasan {$document->adjustment_number} berhasil. Stok dan HPP produk hasil sudah diperbarui.");
    }

    private function repackableQuery($store)
    {
        return $store->products()
            ->where('track_stock', true)
            ->where('unlimited_stock', false)
            ->where('is_bundle', false)
            ->where('has_variants', false)
            ->where('is_active', true);
    }

    /**
     * Pasangan repack yang pernah dipakai, untuk isian cepat: perbandingan hasil per 1 asal.
     *
     * @return list<array{source_product_id: int, output_product_id: int, ratio: float, last_at: string}>
     */
    private function recentPairs(int $storeId): array
    {
        $documents = StockAdjustment::query()
            ->where('store_id', $storeId)
            ->where('type', StockAdjustment::TYPE_REPACK)
            ->where('status', StockAdjustment::STATUS_POSTED)
            ->with('lines:id,stock_adjustment_id,product_id,variance_quantity')
            ->latest('id')
            ->limit(50)
            ->get();

        $pairs = [];
        foreach ($documents as $doc) {
            $src = $doc->lines->first(fn ($l) => (float) $l->variance_quantity < 0);
            $out = $doc->lines->first(fn ($l) => (float) $l->variance_quantity > 0);
            if (! $src || ! $out) {
                continue;
            }
            $key = $src->product_id.'-'.$out->product_id;
            if (isset($pairs[$key])) {
                continue;
            }
            $pairs[$key] = [
                'source_product_id' => (int) $src->product_id,
                'output_product_id' => (int) $out->product_id,
                'ratio' => round(abs((float) $out->variance_quantity) / abs((float) $src->variance_quantity), 4),
                'last_at' => $doc->created_at?->toIso8601String() ?? '',
            ];
        }

        return array_values(array_slice($pairs, 0, 12));
    }
}
