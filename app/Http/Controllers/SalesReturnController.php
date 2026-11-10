<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\ActivityLogger;
use App\Support\Accounting\AccountingPeriodGuard;
use App\Support\Accounting\JournalPoster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SalesReturnController extends Controller
{
    /**
     * Halaman buat retur untuk satu transaksi.
     * Route: GET /sales-returns/create?transaction_id=...
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $store = $request->user()->store;
        abort_unless($store, 404);

        $transaction = Transaction::query()
            ->where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereNull('is_void')
            ->with(['items.product', 'customer'])
            ->when($request->query('transaction_id'), fn ($q, $id) => $q->where('id', $id))
            ->first();

        if (! $transaction) {
            return redirect()->route('transactions.index')->with('error', 'Transaksi tidak ditemukan atau sudah dibatalkan.');
        }

        // Kuota retur per baris: qty terjual - qty sudah diretur.
        $returnedByLine = SalesReturn::query()
            ->where('store_id', $store->id)
            ->where('transaction_id', $transaction->id)
            ->with('lines')
            ->get()
            ->flatMap->lines
            ->groupBy(fn ($l) => $l->product_id.'-'.($l->product_variant_id ?? '0'))
            ->map(fn ($group) => (float) $group->sum('quantity'));

        $lines = $transaction->items->map(function (TransactionItem $item) use ($returnedByLine) {
            $key = $item->product_id.'-'.($item->product_variant_id ?? '0');
            $returned = $returnedByLine[$key] ?? 0.0;
            $sellable = (float) $item->quantity + (float) $item->free_quantity;
            $maxReturnable = max($sellable - $returned, 0);

            return [
                'item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name,
                'variant_label' => $item->variant_label,
                'quantity' => (float) $item->quantity,
                'free_quantity' => (float) $item->free_quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->total_price,
                'max_returnable' => $maxReturnable,
                'already_returned' => $returned,
            ];
        })->filter(fn ($l) => $l['max_returnable'] > 0)->values();

        return Inertia::render('SalesReturns/Create', [
            'transaction' => [
                'id' => $transaction->id,
                'transaction_number' => $transaction->transaction_number,
                'completed_at' => $transaction->completed_at?->toDateTimeString(),
                'payment_method' => $transaction->payment_method,
                'customer' => $transaction->customer ? [
                    'id' => $transaction->customer->id,
                    'name' => $transaction->customer->name,
                    'points_balance' => (float) $transaction->customer->points_balance,
                ] : null,
            ],
            'lines' => $lines,
            'store' => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'IDR'],
        ]);
    }

    /**
     * Simpan retur penjualan.
     * Route: POST /sales-returns
     */
    public function store(Request $request): RedirectResponse
    {
        $store = $request->user()->store;
        abort_unless($store, 404);

        $validated = $request->validate([
            'transaction_id' => 'required|integer|exists:transactions,id',
            'refund_method' => 'required|in:cash,original,store_credit',
            'returned_at' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => 'required|integer|exists:transaction_items,id',
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.refund_amount' => 'required|numeric|min:0',
        ]);

        $transaction = Transaction::query()
            ->where('store_id', $store->id)
            ->where('id', $validated['transaction_id'])
            ->where('status', 'completed')
            ->whereNull('is_void')
            ->with('items')
            ->firstOrFail();

        if ($transaction->items->isEmpty()) {
            return back()->withErrors(['lines' => 'Transaksi tidak memiliki baris item.']);
        }

        // Guard periode akuntansi untuk tanggal retur.
        $returnedAt = $validated['returned_at'] ?? now()->toDateTimeString();
        $lockMessage = AccountingPeriodGuard::posLockMessage($store, $returnedAt);
        if ($lockMessage) {
            return back()->withErrors(['returned_at' => $lockMessage]);
        }

        try {
            $return = DB::transaction(function () use ($store, $request, $transaction, $validated, $returnedAt) {
                return $this->persistReturn($store, $request, $transaction, $validated, $returnedAt);
            });
        } catch (Throwable $e) {
            return back()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales-returns.show', $return->id)
            ->with('success', 'Retur '.$return->return_number.' berhasil dibuat.');
    }

    private function persistReturn($store, $request, $transaction, array $validated, string $returnedAt): SalesReturn
    {
        $lines = collect($validated['lines']);

        // Validasi kuota retur per baris & total refund.
        $items = $transaction->items->keyBy('id');
        $totalRefund = 0.0;
        $returnLinesPayload = [];

        foreach ($lines as $row) {
            $item = $items->get($row['item_id']);
            if (! $item || (int) $item->transaction_id !== (int) $transaction->id) {
                throw new \RuntimeException('Baris item tidak termasuk dalam transaksi ini.');
            }

            $key = $item->product_id.'-'.($item->product_variant_id ?? '0');
            $already = $this->alreadyReturnedForItem($transaction->id, $item);
            $maxReturnable = (float) $item->quantity + (float) $item->free_quantity - $already;

            if ((float) $row['quantity'] > $maxReturnable + 0.0001) {
                throw new \RuntimeException("Kuantitas retur untuk \"{$item->product_name}\" melebihi sisa yang bisa diretur (maks {$maxReturnable}).");
            }

            $refundAmount = (float) $row['refund_amount'];
            $totalRefund += $refundAmount;

            $returnLinesPayload[] = [
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name,
                'quantity' => (float) $row['quantity'],
                'unit_price' => (float) $row['unit_price'],
                'line_total' => (float) $row['quantity'] * (float) $row['unit_price'],
                'refund_amount' => $refundAmount,
            ];
        }

        if ($totalRefund < 0.01) {
            throw new \RuntimeException('Nilai retur tidak boleh nol.');
        }

        $return = SalesReturn::create([
            'store_id' => $store->id,
            'transaction_id' => $transaction->id,
            'customer_id' => $transaction->customer_id,
            'user_id' => $request->user()->id,
            'cash_shift_id' => $this->activeShiftId($store),
            'return_type' => $this->isFullReturn($transaction, $lines) ? 'full' : 'partial',
            'subtotal' => collect($returnLinesPayload)->sum('line_total'),
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $totalRefund,
            'refund_method' => $validated['refund_method'],
            'returned_at' => $returnedAt,
            'notes' => $validated['notes'] ?? null,
        ]);

        foreach ($returnLinesPayload as $payload) {
            $line = $return->lines()->create($payload);
            $this->restockLine($store, $request, $return, $line);
        }

        // Kurangi poin & total belanja pelanggan.
        if ($transaction->customer_id && $transaction->customer) {
            $pointsValue = $this->estimatePointsForReturn($transaction, $totalRefund);
            if ($pointsValue > 0) {
                Customer::query()
                    ->where('id', $transaction->customer_id)
                    ->decrement('points_balance', $pointsValue);
            }
            Customer::query()
                ->where('id', $transaction->customer_id)
                ->where('total_spent', '>=', $totalRefund)
                ->decrement('total_spent', $totalRefund);
        }

        ActivityLogger::log(
            store: $store->id,
            action: 'sales_return.created',
            description: 'Membuat retur penjualan '.$return->return_number.' untuk transaksi '.$transaction->transaction_number,
            subject: $return,
            metadata: [
                'transaction_id' => $transaction->id,
                'total_refund' => $totalRefund,
                'refund_method' => $validated['refund_method'],
            ],
        );

        // Jurnal retur (no-op bila modul akuntansi mati).
        JournalPoster::postSalesReturn($store, $return);

        return $return;
    }

    /**
     * Masukkan kembali stok barang yang diretur + catat pergerakan.
     */
    private function restockLine($store, $request, SalesReturn $return, $line): void
    {
        $product = Product::query()
            ->where('store_id', $store->id)
            ->where('id', $line->product_id)
            ->lockForUpdate()
            ->first();

        if (! $product || ! $product->track_stock) {
            return;
        }

        $qty = (float) $line->quantity;

        if ($line->product_variant_id) {
            $variant = $product->variants()->where('id', $line->product_variant_id)->lockForUpdate()->first();
            if ($variant) {
                $variant->increment('stock_quantity', $qty);
                $product->refresh();
                $product->update(['stock_quantity' => $product->variants()->sum('stock_quantity')]);
                $balanceAfter = (float) $variant->fresh()->stock_quantity;
            }
        } else {
            $product->increment('stock_quantity', $qty);
            $balanceAfter = (float) $product->fresh()->stock_quantity;
        }

        StockMovement::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => $line->product_variant_id,
            'user_id' => $request->user()->id,
            'quantity_change' => $qty,
            'type' => StockMovement::TYPE_RETURN,
            'reference_type' => SalesReturn::class,
            'reference_id' => $return->id,
            'balance_after' => $balanceAfter ?? 0,
            'notes' => $return->return_number.' (retur penjualan)',
        ]);
    }

    private function alreadyReturnedForItem(int $transactionId, TransactionItem $item): float
    {
        $q = \App\Models\SalesReturnLine::query()
            ->whereHas('salesReturn', fn ($qr) => $qr->where('transaction_id', $transactionId))
            ->where('product_id', $item->product_id);

        if ($item->product_variant_id) {
            $q->where('product_variant_id', $item->product_variant_id);
        } else {
            $q->whereNull('product_variant_id');
        }

        return (float) $q->sum('quantity');
    }

    private function isFullReturn(Transaction $transaction, $lines): bool
    {
        $itemIds = $transaction->items->pluck('id');
        $returnedQty = $lines->sum('quantity');
        $totalQty = (float) $transaction->items->sum(fn ($i) => (float) $i->quantity + (float) $i->free_quantity);

        return $itemIds->count() === $lines->count() && abs($returnedQty - $totalQty) < 0.0001;
    }

    private function estimatePointsForReturn(Transaction $transaction, float $totalRefund): int
    {
        if ((float) $transaction->total_amount <= 0) {
            return 0;
        }

        // Proporsional poin berdasarkan nilai refund.
        $ratio = $totalRefund / (float) $transaction->total_amount;
        $points = (int) floor(((float) $transaction->total_amount * $ratio) / 10000);

        return max($points, 0);
    }

    private function activeShiftId($store): ?int
    {
        return \App\Models\CashShift::query()
            ->where('store_id', $store->id)
            ->whereNull('closed_at')
            ->orderByDesc('opened_at')
            ->value('id');
    }

    /**
     * Riwayat retur penjualan.
     * Route: GET /sales-returns
     */
    public function index(Request $request): Response
    {
        $store = $request->user()->store;
        abort_unless($store, 404);

        $returns = SalesReturn::query()
            ->where('store_id', $store->id)
            ->with(['transaction:id,transaction_number', 'user:id,name', 'lines'])
            ->latest('returned_at')
            ->limit(100)
            ->get()
            ->map(fn (SalesReturn $r) => [
                'id' => $r->id,
                'return_number' => $r->return_number,
                'returned_at' => $r->returned_at?->toDateTimeString(),
                'transaction_number' => $r->transaction?->transaction_number,
                'customer_name' => $r->customer?->name,
                'total_amount' => (float) $r->total_amount,
                'refund_method' => $r->refund_method,
                'return_type' => $r->return_type,
                'user_name' => $r->user?->name,
                'items_count' => $r->lines->count(),
            ]);

        return Inertia::render('SalesReturns/Index', [
            'returns' => $returns,
            'store' => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'IDR'],
        ]);
    }

    /**
     * Detail retur penjualan.
     * Route: GET /sales-returns/{salesReturn}
     */
    public function show(Request $request, SalesReturn $salesReturn): Response
    {
        $store = $request->user()->store;
        abort_unless($store && (int) $salesReturn->store_id === (int) $store->id, 404);

        $salesReturn->load(['lines.product:id,name,sku', 'transaction:id,transaction_number,completed_at,payment_method', 'user:id,name', 'customer:id,name,phone']);

        return Inertia::render('SalesReturns/Show', [
            'return' => [
                'id' => $salesReturn->id,
                'return_number' => $salesReturn->return_number,
                'return_type' => $salesReturn->return_type,
                'refund_method' => $salesReturn->refund_method,
                'returned_at' => $salesReturn->returned_at?->toDateTimeString(),
                'total_amount' => (float) $salesReturn->total_amount,
                'subtotal' => (float) $salesReturn->subtotal,
                'notes' => $salesReturn->notes,
                'transaction' => $salesReturn->transaction ? [
                    'id' => $salesReturn->transaction->id,
                    'transaction_number' => $salesReturn->transaction->transaction_number,
                    'completed_at' => $salesReturn->transaction->completed_at?->toDateTimeString(),
                    'payment_method' => $salesReturn->transaction->payment_method,
                ] : null,
                'customer' => $salesReturn->customer ? [
                    'id' => $salesReturn->customer->id,
                    'name' => $salesReturn->customer->name,
                    'phone' => $salesReturn->customer->phone,
                ] : null,
                'user_name' => $salesReturn->user?->name,
                'lines' => $salesReturn->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'product_name' => $l->product_name ?? $l->product?->name,
                    'variant_label' => $l->productVariant?->name,
                    'quantity' => (float) $l->quantity,
                    'unit_price' => (float) $l->unit_price,
                    'refund_amount' => (float) $l->refund_amount,
                ]),
            ],
            'store' => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'IDR'],
        ]);
    }
}
