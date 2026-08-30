<?php

namespace App\Http\Controllers;

use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockMovement;
use App\Support\PharmacyFeatures;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Laporan turunan modul koreksi stok:
 *  - Laporan selisih stok: rekap nilai kerugian/keuntungan per alasan dan per produk.
 *  - Kartu stok: mutasi satu produk berurutan waktu dengan saldo berjalan.
 */
class StockReportController extends Controller
{
    /**
     * Rekap selisih dari dokumen koreksi yang sudah diposting.
     */
    public function variance(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'reason_code' => ['nullable', 'string', Rule::in(array_keys(StockAdjustment::reasonLabels()))],
            'type' => ['nullable', 'string', Rule::in([StockAdjustment::TYPE_ADJUSTMENT, StockAdjustment::TYPE_OPNAME])],
        ]);

        $from = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $to = $validated['date_to'] ?? now()->toDateString();

        // Hanya dokumen terposting yang memengaruhi stok, sehingga hanya itu yang dilaporkan.
        $documents = StockAdjustment::query()
            ->where('store_id', $store->id)
            ->where('status', StockAdjustment::STATUS_POSTED)
            ->whereDate('posted_at', '>=', $from)
            ->whereDate('posted_at', '<=', $to)
            ->when(! empty($validated['reason_code']), fn ($q) => $q->where('reason_code', $validated['reason_code']))
            ->when(! empty($validated['type']), fn ($q) => $q->where('type', $validated['type']));

        $documentIds = (clone $documents)->pluck('id');

        $byReason = (clone $documents)
            ->selectRaw('reason_code, COUNT(*) as document_count, COALESCE(SUM(total_variance_value), 0) as total_value')
            ->groupBy('reason_code')
            ->get()
            ->map(fn ($row) => [
                'reason_code' => $row->reason_code,
                'reason_label' => StockAdjustment::reasonLabel((string) $row->reason_code),
                'document_count' => (int) $row->document_count,
                'total_value' => (float) $row->total_value,
            ])
            ->sortBy('total_value')
            ->values();

        $byProduct = StockAdjustmentLine::query()
            ->whereIn('stock_adjustment_id', $documentIds)
            ->where('variance_quantity', '!=', 0)
            ->with(['product:id,name,sku,unit', 'productVariant:id,name'])
            ->selectRaw('product_id, product_variant_id, SUM(variance_quantity) as qty, SUM(variance_value) as value, COUNT(*) as line_count')
            ->groupBy('product_id', 'product_variant_id')
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product?->name ?? '—',
                'variant_name' => $row->productVariant?->name,
                'sku' => $row->product?->sku,
                'unit' => $row->product?->unit,
                'quantity' => (float) $row->qty,
                'value' => (float) $row->value,
                'line_count' => (int) $row->line_count,
            ])
            ->sortBy('value')
            ->values();

        $totalValue = (float) (clone $documents)->sum('total_variance_value');
        $lossValue = $byProduct->where('value', '<', 0)->sum('value');
        $gainValue = $byProduct->where('value', '>', 0)->sum('value');

        return Inertia::render('Reports/StockVariance', [
            'summary' => [
                'document_count' => (clone $documents)->count(),
                'affected_items' => $byProduct->count(),
                'total_value' => $totalValue,
                'loss_value' => (float) $lossValue,
                'gain_value' => (float) $gainValue,
            ],
            'byReason' => $byReason,
            'byProduct' => $byProduct,
            'filters' => [
                'date_from' => $from,
                'date_to' => $to,
                'reason_code' => $validated['reason_code'] ?? null,
                'type' => $validated['type'] ?? null,
            ],
            'reasonLabels' => StockAdjustment::reasonLabels(),
        ]);
    }

    /**
     * Laporan pemusnahan dari mutasi damage.
     */
    public function destructions(Request $request): Response|RedirectResponse
    {
        $store = $request->user()->store;
        abort_unless(PharmacyFeatures::enabled($store), 404);
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $from = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $to = $validated['date_to'] ?? now()->toDateString();
        $movements = StockMovement::query()->where('store_id', $store->id)
            ->where('type', StockMovement::TYPE_DAMAGE)
            ->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)
            ->with(['product:id,name,sku,unit', 'user:id,name'])
            ->latest()->paginate(25)->withQueryString();

        return Inertia::render('Reports/Destructions', [
            'movements' => $movements,
            'summary' => ['quantity' => (float) abs((clone $movements->getCollection())->sum('quantity_change')), 'count' => $movements->total()],
            'filters' => ['date_from' => $from, 'date_to' => $to],
        ]);
    }

    /**
     * Kartu stok satu produk: seluruh mutasi berurutan waktu dengan saldo berjalan.
     */
    public function stockCard(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('store_id', $store->id)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $from = $validated['date_from'] ?? now()->subDays(30)->toDateString();
        $to = $validated['date_to'] ?? now()->toDateString();

        $products = $store->products()
            ->where('track_stock', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'unit', 'stock_quantity']);

        $productId = $validated['product_id'] ?? $products->first()?->id;
        $product = $productId ? $products->firstWhere('id', (int) $productId) : null;

        $movements = collect();
        $openingBalance = 0.0;

        if ($product) {
            // Saldo awal = saldo setelah mutasi terakhir sebelum rentang laporan.
            $previous = StockMovement::query()
                ->where('store_id', $store->id)
                ->where('product_id', $product->id)
                ->whereDate('created_at', '<', $from)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            $openingBalance = $previous ? (float) $previous->balance_after : 0.0;

            $movements = StockMovement::query()
                ->where('store_id', $store->id)
                ->where('product_id', $product->id)
                ->whereDate('created_at', '>=', $from)
                ->whereDate('created_at', '<=', $to)
                ->with(['user:id,name', 'productVariant:id,name'])
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (StockMovement $m) => [
                    'id' => $m->id,
                    'created_at' => $m->created_at?->toIso8601String(),
                    'type' => $m->type,
                    'type_label' => self::typeLabels()[$m->type] ?? $m->type,
                    'variant_name' => $m->productVariant?->name,
                    'quantity_change' => (float) $m->quantity_change,
                    'balance_after' => (float) $m->balance_after,
                    'notes' => $m->notes,
                    'user_name' => $m->user?->name,
                ]);
        }

        $totalIn = $movements->where('quantity_change', '>', 0)->sum('quantity_change');
        $totalOut = $movements->where('quantity_change', '<', 0)->sum('quantity_change');

        return Inertia::render('Reports/StockCard', [
            'products' => $products,
            'product' => $product,
            'movements' => $movements->values(),
            'summary' => [
                'opening_balance' => $openingBalance,
                'total_in' => (float) $totalIn,
                'total_out' => (float) abs($totalOut),
                'closing_balance' => $movements->isNotEmpty()
                    ? (float) $movements->last()['balance_after']
                    : $openingBalance,
                'movement_count' => $movements->count(),
            ],
            'filters' => [
                'product_id' => $product?->id,
                'date_from' => $from,
                'date_to' => $to,
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function typeLabels(): array
    {
        return [
            StockMovement::TYPE_SALE => 'Penjualan',
            StockMovement::TYPE_ADJUSTMENT => 'Penyesuaian',
            StockMovement::TYPE_PURCHASE => 'Pembelian',
            StockMovement::TYPE_SUPPLIER_RETURN => 'Retur ke supplier',
            StockMovement::TYPE_RETURN => 'Retur',
            StockMovement::TYPE_DAMAGE => 'Rusak / hilang',
            StockMovement::TYPE_TRANSFER_OUT => 'Transfer keluar',
            StockMovement::TYPE_TRANSFER_IN => 'Transfer masuk',
            StockMovement::TYPE_TRANSFER_REVERSAL => 'Pembatalan transfer',
            StockMovement::TYPE_PRODUCTION_CONSUMPTION => 'Konsumsi produksi',
            StockMovement::TYPE_PRODUCTION_OUTPUT => 'Output produksi',
            StockMovement::TYPE_PRODUCTION_REVERSAL => 'Pembatalan produksi',
            StockMovement::TYPE_OPNAME => 'Stock opname',
            StockMovement::TYPE_ADJUSTMENT_REVERSAL => 'Pembatalan penyesuaian',
        ];
    }
}
