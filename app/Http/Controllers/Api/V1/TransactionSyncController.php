<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SalesChannel;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransactionSyncController extends Controller
{
    /**
     * Menerima transaksi penjualan offline yang disinkronkan oleh perangkat mobile.
     * Menggunakan `client_uuid` (offline_sale_id) untuk memastikan idempotency.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_uuid' => ['required', 'string', 'uuid'],
            'transaction_number' => ['nullable', 'string', 'max:50'],
            'payment_method' => ['required', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'amount_paid' => ['required', 'numeric', 'min:0'],
            'change_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'completed_at' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $storeId = $user->store_id;

        // Idempotency check: jika sudah pernah tersinkron, kembalikan transaksi yang ada
        $existing = Transaction::query()
            ->where('store_id', $storeId)
            ->where('offline_sale_id', $validated['client_uuid'])
            ->first();

        if ($existing) {
            return response()->json([
                'status' => 'success',
                'message' => 'Transaksi sudah disinkronkan sebelumnya (idempoten).',
                'idempotent' => true,
                'data' => [
                    'id' => $existing->id,
                    'transaction_number' => $existing->transaction_number,
                    'client_uuid' => $existing->offline_sale_id,
                ],
            ], 200);
        }

        $transaction = DB::transaction(function () use ($validated, $user, $storeId) {
            $txNumber = $validated['transaction_number'] ?? 'TRX-MOB-'.strtoupper(Str::random(8));

            $trx = Transaction::create([
                'store_id' => $storeId,
                'user_id' => $user->id,
                'transaction_number' => $txNumber,
                'offline_sale_id' => $validated['client_uuid'],
                'status' => 'completed',
                'order_status' => 'completed',
                'payment_method' => $validated['payment_method'],
                'payment_status' => 'paid',
                'subtotal' => $validated['subtotal'],
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'total_amount' => $validated['total_amount'],
                'amount_paid' => $validated['amount_paid'],
                'change_amount' => $validated['change_amount'] ?? 0,
                'notes' => $validated['notes'] ?? 'Disinkronkan via Android POS',
                'sales_channel' => SalesChannel::WalkIn,
                'completed_at' => $validated['completed_at'] ?? now(),
            ]);

            foreach ($validated['items'] as $itemData) {
                $subtotal = $itemData['quantity'] * $itemData['unit_price'];
                $product = Product::find($itemData['product_id']);

                TransactionItem::create([
                    'transaction_id' => $trx->id,
                    'product_id' => $itemData['product_id'],
                    'product_name' => $product?->name ?? 'Produk POS',
                    'product_sku' => $product?->sku,
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'total_price' => $subtotal,
                ]);

                // Kurangi stok produk secara atomik
                Product::where('id', $itemData['product_id'])->decrement('stock_quantity', $itemData['quantity']);
            }

            return $trx;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Transaksi berhasil disinkronkan ke server.',
            'idempotent' => false,
            'data' => [
                'id' => $transaction->id,
                'transaction_number' => $transaction->transaction_number,
                'client_uuid' => $transaction->offline_sale_id,
            ],
        ], 201);
    }
}
