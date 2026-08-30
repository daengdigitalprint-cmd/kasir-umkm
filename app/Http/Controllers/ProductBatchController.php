<?php

namespace App\Http\Controllers;

use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Services\ActivityLogger;
use App\Support\PharmacyFeatures;
use App\Support\ProductQuantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductBatchController extends Controller
{
    public function destroy(Request $request, ProductBatch $productBatch): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:1000']]);
        $this->assertAccess($request, $productBatch);

        DB::transaction(function () use ($request, $productBatch, $data) {
            $batch = ProductBatch::query()->whereKey($productBatch->id)->lockForUpdate()->firstOrFail();
            $product = $batch->product()->lockForUpdate()->firstOrFail();
            abort_unless($batch->status === ProductBatch::STATUS_AVAILABLE, 422, 'Batch tidak tersedia untuk dimusnahkan.');
            $quantity = ProductQuantity::normalizeLineQuantity($product, $data['quantity']);
            abort_unless(ProductQuantity::cmp((string) $batch->quantity_remaining, $quantity) >= 0, 422, 'Jumlah melebihi stok batch.');

            $remaining = ProductQuantity::sub((string) $batch->quantity_remaining, $quantity);
            $batch->update([
                'quantity_remaining' => $remaining,
                'status' => ProductQuantity::cmp($remaining, '0.0000') === 0 ? ProductBatch::STATUS_DESTROYED : $batch->status,
            ]);
            $synced = (string) ProductBatch::query()->where('product_id', $product->id)->sum('quantity_remaining');
            $product->update(['stock_quantity' => ProductQuantity::normalizeStorageAmount($product, $synced)]);
            StockMovement::create([
                'store_id' => $batch->store_id, 'product_id' => $product->id, 'user_id' => $request->user()->id,
                'quantity_change' => ProductQuantity::sub('0.0000', $quantity), 'type' => StockMovement::TYPE_DAMAGE,
                'reference_type' => ProductBatch::class, 'reference_id' => $batch->id,
                'balance_after' => $product->stock_quantity, 'notes' => $data['reason'],
            ]);
            ActivityLogger::log($batch->store_id, 'product_batch.destroyed', 'Stok batch dimusnahkan', $batch, ['quantity' => $quantity, 'reason' => $data['reason']]);
        });

        return back()->with('success', 'Stok batch berhasil dimusnahkan.');
    }

    public function quarantine(Request $request, ProductBatch $productBatch): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->assertAccess($request, $productBatch);

        abort_unless($productBatch->status === ProductBatch::STATUS_AVAILABLE, 422, 'Batch tidak tersedia untuk dikarantina.');

        DB::transaction(function () use ($request, $productBatch, $data) {
            $productBatch->update([
                'status' => ProductBatch::STATUS_QUARANTINED,
                'quarantine_reason' => $data['reason'],
                'quarantined_by' => $request->user()->id,
                'quarantined_at' => now(),
            ]);
            StockMovement::create([
                'store_id' => $productBatch->store_id,
                'product_id' => $productBatch->product_id,
                'user_id' => $request->user()->id,
                'quantity_change' => 0,
                'type' => 'batch_quarantine',
                'reference_type' => ProductBatch::class,
                'reference_id' => $productBatch->id,
                'balance_after' => $productBatch->product->stock_quantity,
                'notes' => $data['reason'],
            ]);
            ActivityLogger::log($productBatch->store_id, 'product_batch.quarantined', 'Batch dikarantina', $productBatch, ['reason' => $data['reason']]);
        });

        return back()->with('success', 'Batch berhasil dikarantina.');
    }

    public function release(Request $request, ProductBatch $productBatch): RedirectResponse
    {
        $this->assertAccess($request, $productBatch);
        abort_unless($productBatch->status === ProductBatch::STATUS_QUARANTINED, 422, 'Batch bukan batch karantina.');
        abort_if($productBatch->expiry_date && $productBatch->expiry_date->isPast(), 422, 'Batch sudah kedaluwarsa.');
        abort_unless($productBatch->expiry_date, 422, 'Batch Apotek wajib memiliki ED.');

        DB::transaction(function () use ($request, $productBatch) {
            $productBatch->update([
                'status' => ProductBatch::STATUS_AVAILABLE,
                'released_by' => $request->user()->id,
                'released_at' => now(),
            ]);
            StockMovement::create([
                'store_id' => $productBatch->store_id,
                'product_id' => $productBatch->product_id,
                'user_id' => $request->user()->id,
                'quantity_change' => 0,
                'type' => 'batch_release',
                'reference_type' => ProductBatch::class,
                'reference_id' => $productBatch->id,
                'balance_after' => $productBatch->product->stock_quantity,
                'notes' => 'Batch dilepas dari karantina',
            ]);
            ActivityLogger::log($productBatch->store_id, 'product_batch.released', 'Batch dilepas dari karantina', $productBatch);
        });

        return back()->with('success', 'Batch berhasil dilepas dari karantina.');
    }

    private function assertAccess(Request $request, ProductBatch $batch): void
    {
        abort_unless((int) $request->user()->store_id === (int) $batch->store_id, 403);
        abort_unless(PharmacyFeatures::enabled($batch->product->store), 404);
    }
}
