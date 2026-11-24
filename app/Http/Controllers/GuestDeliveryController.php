<?php

namespace App\Http\Controllers;

use App\Enums\SalesChannel;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Support\DeliveryFeatures;
use App\Support\ScanMejaAppearance;
use App\Support\UpsellSuggestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * F10 — Pesan Antar mandiri: pelanggan memesan lewat tautan toko (tanpa login),
 * pesanan masuk ke menu Pengantaran sebagai "Menunggu konfirmasi" untuk diterima kasir.
 *
 * Halaman tamunya memakai ulang halaman menu digital Scan Meja (mode antar) supaya
 * tampilan pemesanan di meja dan pemesanan antar tetap sama.
 */
class GuestDeliveryController extends Controller
{
    /** Halaman menu pesan antar. URL: /antar/{slug toko} */
    public function show(string $slug)
    {
        $store = Store::query()->where('slug', $slug)->firstOrFail();

        if ($inactive = $this->inactivePage($store)) {
            return $inactive;
        }

        $categories = GuestMenuController::menuCategories($store);

        return Inertia::render('ScanMeja/Guest/Menu', [
            'table' => null,
            'delivery' => $this->deliveryProps($store),
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
                'address' => $store->address,
                'phone' => $store->phone,
                ...ScanMejaAppearance::urls($store),
            ],
            'categories' => $categories,
            'bestSelling' => GuestMenuController::bestSelling($store, $categories, 'delivery'),
            'paymentMethods' => self::paymentMethods($store),
            'upsellMap' => UpsellSuggestions::map($store),
        ]);
    }

    /** Halaman status pesanan antar. URL: /antar/{slug}/status/{statusToken} */
    public function status(string $slug, string $statusToken)
    {
        $store = Store::query()->where('slug', $slug)->firstOrFail();

        if (! $store->is_active) {
            return Inertia::render('ScanMeja/Guest/StoreInactive');
        }

        // Pesanan yang sudah dibuat tetap bisa dipantau walau pemesanan mandiri kemudian ditutup.
        $transaction = $this->findOrder($statusToken, $store->id);

        return Inertia::render('ScanMeja/Guest/OrderStatus', [
            'table' => null,
            'delivery' => [
                ...$this->deliveryProps($store),
                'recipient_name' => $transaction->deliveryOrder?->recipient_name,
                'address' => $transaction->deliveryOrder?->address,
            ],
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
                'phone' => $store->phone,
                ...ScanMejaAppearance::urls($store),
            ],
            'paymentMethod' => self::paymentMethods($store)->firstWhere('type', $transaction->payment_method),
            'transaction' => [
                ...self::statusPayload($transaction),
                'subtotal' => $transaction->subtotal,
                'notes' => $transaction->notes,
                'created_at' => $transaction->created_at->toISOString(),
            ],
        ]);
    }

    /** Buat pesanan antar dari pelanggan. POST /api/guest/delivery-order */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_slug' => 'required|string|max:255',
            'recipient_name' => 'required|string|min:2|max:120',
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s().]{8,30}$/'],
            'address' => 'required|string|min:10|max:500',
            'notes' => 'nullable|string|max:500',
            'payment_method' => 'nullable|string|in:cash,qris,ewallet,transfer,bank_transfer',
            'items' => 'required|array|min:1|max:50',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:99',
            'items.*.notes' => 'nullable|string|max:200',
        ], [
            'recipient_name.required' => 'Nama penerima wajib diisi.',
            'phone.required' => 'Nomor HP wajib diisi.',
            'phone.regex' => 'Nomor HP tidak valid.',
            'address.required' => 'Alamat pengantaran wajib diisi.',
            'address.min' => 'Alamat pengantaran terlalu singkat. Tulis lengkap dengan patokan.',
        ]);

        $store = Store::query()->where('slug', $validated['store_slug'])->first();

        if (! $store || ! $store->is_active || ! DeliveryFeatures::selfOrderEnabled($store)) {
            return response()->json(['error' => 'Pesan antar sedang tidak tersedia untuk toko ini.'], 403);
        }

        // Harga dan total selalu dihitung dari data produk di server (harga toko).
        $subtotal = '0.00';
        $itemData = [];
        foreach ($validated['items'] as $item) {
            $product = Product::query()
                ->where('id', $item['product_id'])
                ->where('store_id', $store->id)
                ->where('is_active', true)
                ->where('inventory_type', 'sellable')
                ->first();

            if (! $product) {
                return response()->json(['error' => 'Ada menu yang sudah tidak tersedia. Muat ulang halaman lalu pesan kembali.'], 422);
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
                'modifiers' => ! empty($item['notes']) ? [['name' => 'Catatan', 'value' => $item['notes']]] : null,
                'product_snapshot' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'description' => $product->description,
                ],
            ];
        }

        $fee = number_format(DeliveryFeatures::defaultFee($store), 2, '.', '');
        $total = bcadd($subtotal, $fee, 2);

        // Metode bayar harus salah satu yang diaktifkan toko; selain itu bayar di tempat.
        $allowedMethods = self::paymentMethods($store)->pluck('type')->all();
        $paymentMethod = in_array($validated['payment_method'] ?? null, $allowedMethods, true)
            ? $validated['payment_method']
            : ($allowedMethods[0] ?? 'cash');

        $recipient = trim($validated['recipient_name']);
        $phone = trim($validated['phone']);
        $address = trim($validated['address']);
        $notes = isset($validated['notes']) ? trim($validated['notes']) : '';

        $transaction = DB::transaction(function () use ($store, $itemData, $subtotal, $fee, $total, $paymentMethod, $recipient, $phone, $address, $notes) {
            $txn = Transaction::create([
                'store_id' => $store->id,
                'user_id' => null, // Pesanan tamu — belum ada kasir yang menangani
                'status_token' => Str::random(64),
                'status' => 'pending',
                'order_status' => 'pending',
                'payment_method' => $paymentMethod,
                'payment_status' => 'unpaid',
                'subtotal' => $subtotal,
                'delivery_fee' => $fee,
                'total_amount' => $total,
                'amount_paid' => '0.00',
                'change_amount' => '0.00',
                'order_type' => 'delivery',
                'sales_channel' => SalesChannel::Delivery->value,
                'notes' => $notes !== '' ? $notes : "Pesan antar dari {$recipient} (tautan toko)",
                'payment_details' => [
                    'source' => DeliveryFeatures::SELF_ORDER_SOURCE,
                    'customer_name' => $recipient,
                    'customer_phone' => $phone,
                    'payment_method' => $paymentMethod,
                ],
            ]);

            foreach ($itemData as $item) {
                TransactionItem::create([...$item, 'transaction_id' => $txn->id]);
            }

            DeliveryOrder::create([
                'store_id' => $store->id,
                'transaction_id' => $txn->id,
                'recipient_name' => $recipient,
                'phone' => $phone,
                'address' => $address,
                'delivery_fee' => $fee,
                'status' => DeliveryOrder::STATUS_PENDING,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            return $txn;
        });

        return response()->json([
            'success' => true,
            'message' => 'Pesanan terkirim. Mohon tunggu konfirmasi dari toko.',
            'transaction' => [
                'number' => $transaction->transaction_number,
                'id' => $transaction->id,
                'status_token' => $transaction->status_token,
                'total' => $transaction->total_amount,
                'status' => 'pending',
            ],
        ]);
    }

    /** Status pesanan antar untuk polling halaman tamu. GET /api/guest/delivery-order/{statusToken}/status */
    public function statusJson(string $statusToken): JsonResponse
    {
        return response()->json(self::statusPayload($this->findOrder($statusToken)));
    }

    /**
     * Metode bayar pesan antar: memakai daftar "Pembayaran menu digital" toko, dengan
     * tunai ditampilkan sebagai bayar di tempat (COD) ke kurir.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public static function paymentMethods(Store $store): \Illuminate\Support\Collection
    {
        return GuestMenuController::paymentMethods($store)
            ->map(function (array $method) {
                if ($method['type'] === 'cash') {
                    $method['label'] = 'Bayar di tempat';
                    $method['instructions'] = 'Bayar tunai ke kurir saat pesanan tiba.';
                }

                return $method;
            })
            ->values();
    }

    /**
     * Status untuk halaman tamu, diturunkan dari status pengantaran:
     * pending → accepted (disiapkan) → on_the_way (diantar) → completed, atau cancelled.
     *
     * @return array<string, mixed>
     */
    public static function statusPayload(Transaction $transaction): array
    {
        $transaction->loadMissing(['items', 'deliveryOrder']);
        $delivery = $transaction->deliveryOrder;

        $status = match ($delivery?->status) {
            DeliveryOrder::STATUS_PREPARING => 'accepted',
            DeliveryOrder::STATUS_ON_THE_WAY => 'on_the_way',
            DeliveryOrder::STATUS_DELIVERED => 'completed',
            DeliveryOrder::STATUS_CANCELLED => 'cancelled',
            default => $transaction->order_status === 'cancelled' ? 'cancelled' : 'pending',
        };

        return [
            'id' => $transaction->id,
            'status_token' => $transaction->status_token,
            'number' => $transaction->transaction_number,
            'status' => $status,
            'payment_status' => $transaction->payment_status,
            'payment_method' => $transaction->payment_method,
            'delivery_fee' => (float) $transaction->delivery_fee,
            'total_amount' => $transaction->total_amount,
            'reject_reason' => GuestMenuController::rejectReason($transaction),
            'items' => $transaction->items->map(fn ($i) => GuestMenuController::guestItem($i)),
        ];
    }

    private function findOrder(string $statusToken, ?int $storeId = null): Transaction
    {
        return Transaction::query()
            ->where('status_token', $statusToken)
            ->where('payment_details->source', DeliveryFeatures::SELF_ORDER_SOURCE)
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
            ->with(['items', 'deliveryOrder'])
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function deliveryProps(Store $store): array
    {
        return [
            'slug' => $store->slug,
            'fee' => DeliveryFeatures::defaultFee($store),
        ];
    }

    private function inactivePage(Store $store): ?\Inertia\Response
    {
        if (! $store->is_active) {
            return Inertia::render('ScanMeja/Guest/StoreInactive');
        }

        if (! DeliveryFeatures::selfOrderEnabled($store)) {
            return Inertia::render('ScanMeja/Guest/ModuleInactive', ['delivery' => true]);
        }

        return null;
    }
}
