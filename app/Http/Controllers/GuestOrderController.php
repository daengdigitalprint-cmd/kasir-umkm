<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Table;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Support\ScanMejaFeatures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GuestOrderController extends Controller
{
    /**
     * Proses order dari guest (pelanggan scan meja).
     * POST /api/guest/order
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'table_slug' => 'required|string|exists:tables,slug',
            'customer_name' => 'nullable|string|max:100',
            'payment_method' => 'nullable|string|in:cash,qris,ewallet,transfer,bank_transfer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1|max:99',
            'items.*.notes' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:500',
        ]);

        $table = Table::where('slug', $validated['table_slug'])->with('store')->firstOrFail();
        $store = $table->store;

        if (! ScanMejaFeatures::enabled($store)) {
            return response()->json(['error' => 'Modul Scan Meja tidak aktif.'], 403);
        }

        if (! $table->is_active) {
            return response()->json(['error' => 'Meja tidak aktif.'], 403);
        }

        // Hitung subtotal
        $subtotal = '0.00';
        $itemData = [];

        foreach ($validated['items'] as $item) {
            $product = Product::where('id', $item['product_id'])
                ->where('store_id', $store->id)
                ->where('is_active', true)
                ->where('inventory_type', 'sellable')
                ->first();

            if (! $product) {
                return response()->json([
                    'error' => "Produk #{$item['product_id']} tidak ditemukan atau tidak aktif.",
                ], 422);
            }

            $qty = (int) $item['quantity'];
            $lineTotal = bcmul((string) $product->price, (string) $qty, 2);
            $subtotal = bcadd($subtotal, $lineTotal, 2);

            $itemData[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'quantity' => $qty,
                'unit_price' => $product->price,
                'total_price' => $lineTotal,
                'modifiers' => isset($item['notes']) ? [['name' => 'Catatan', 'value' => $item['notes']]] : null,
                'product_snapshot' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'description' => $product->description,
                ],
            ];
        }

        $totalAmount = $subtotal; // No tax/discount for guest orders (simplified)

        $paymentMethod = $validated['payment_method'] ?? 'cash';
        $customerName = $validated['customer_name'] ?? null;

        $transaction = DB::transaction(function () use ($store, $table, $validated, $itemData, $subtotal, $totalAmount, $paymentMethod, $customerName) {
            $txn = Transaction::create([
                'store_id' => $store->id,
                'user_id' => null, // Guest order — no logged-in user
                'status_token' => Str::random(64),
                'status' => 'pending',
                'order_status' => 'pending',
                'payment_method' => $paymentMethod,
                'payment_status' => 'unpaid',
                'subtotal' => $subtotal,
                'total_amount' => $totalAmount,
                'amount_paid' => '0.00',
                'change_amount' => '0.00',
                'order_type' => 'dine_in',
                'table_reference' => $table->name,
                'notes' => $validated['notes'] ?? "Pesanan dari {$table->name} (Scan Meja)",
                'payment_details' => [
                    'source' => 'scan_meja',
                    'table_id' => $table->id,
                    'table_slug' => $table->slug,
                    'customer_name' => $customerName,
                    'payment_method' => $paymentMethod,
                ],
            ]);

            foreach ($itemData as $item) {
                TransactionItem::create([
                    ...$item,
                    'transaction_id' => $txn->id,
                ]);
            }

            return $txn;
        });

        // Auto-create queue entry (notifikasi dapur) jika modul antrian aktif
        if ($store->settings['queue_module_enabled'] ?? false) {
            try {
                $queue = $store->queues()->where('is_active', true)->first();
                if (! $queue) {
                    $queue = $store->queues()->create([
                        'name' => 'Kasir',
                        'code' => 'Q001',
                        'is_active' => true,
                    ]);
                }

                $lastNumber = $queue->entries()->max('number');
                $nextNumber = $lastNumber ? ((int) $lastNumber + 1) : 1;

                $queueItems = collect($itemData)->map(fn ($item) => [
                    'product_name' => $item['product_name'],
                    'quantity' => (int) $item['quantity'],
                ])->toArray();

                $queue->entries()->create([
                    'store_id' => $store->id,
                    'number' => $nextNumber,
                    'status' => 'waiting',
                    'order_type' => 'dine_in',
                    'table_number' => $table->name,
                    'customer_name' => $customerName,
                    'transaction_id' => $transaction->id,
                    'items' => $queueItems,
                ]);
            } catch (\Throwable $e) {
                \Log::warning('[scan-meja] gagal membuat antrian dapur otomatis', [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Pesanan berhasil dibuat! Silakan tunggu pesanan Anda.',
            'transaction' => [
                'number' => $transaction->transaction_number,
                'id' => $transaction->id,
                'status_token' => $transaction->status_token,
                'total' => $transaction->total_amount,
                'status' => $transaction->status,
            ],
        ]);
    }

    /**
     * Cek status pesanan guest.
     * GET /api/guest/order/{statusToken}/status
     */
    public function status(string $statusToken)
    {
        $transaction = Transaction::where('status_token', $statusToken)
            ->where('payment_details->source', 'scan_meja')
            ->firstOrFail();

        return response()->json([
            'id' => $transaction->id,
            'status_token' => $transaction->status_token,
            'number' => $transaction->transaction_number,
            'status' => $transaction->order_status,
            'payment_status' => $transaction->payment_status,
            'total_amount' => $transaction->total_amount,
            'items' => $transaction->items->map(fn ($i) => [
                'name' => $i->product_name,
                'quantity' => $i->quantity,
                'price' => $i->unit_price,
                'total' => $i->total_price,
            ]),
        ]);
    }
}
