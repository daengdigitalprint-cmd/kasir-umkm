<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use App\Models\Table;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Support\ScanMejaFeatures;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GuestMenuController extends Controller
{
    /**
     * Halaman menu digital untuk pelanggan (guest).
     * URL: /m/{slug}
     */
    public function show(Request $request, string $slug)
    {
        $table = Table::where('slug', $slug)->with('store')->firstOrFail();

        $store = $table->store;

        if (! $store->is_active) {
            return Inertia::render('ScanMeja/Guest/StoreInactive');
        }

        if (! ScanMejaFeatures::enabled($store)) {
            return Inertia::render('ScanMeja/Guest/ModuleInactive');
        }

        if (! $table->is_active) {
            return Inertia::render('ScanMeja/Guest/TableInactive');
        }

        $categories = self::menuCategories($store);
        $bestSelling = self::bestSelling($store, $categories, 'dine_in');
        $paymentMethods = self::paymentMethods($store);

        return Inertia::render('ScanMeja/Guest/Menu', [
            'table' => [
                'id' => $table->id,
                'name' => $table->name,
                'slug' => $table->slug,
                'capacity' => $table->capacity,
            ],
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
                'address' => $store->address,
                'phone' => $store->phone,
                ...\App\Support\ScanMejaAppearance::urls($store),
            ],
            'categories' => $categories,
            'bestSelling' => $bestSelling,
            'paymentMethods' => $paymentMethods,
            // F8: saran tambahan menu untuk tamu; kosong bila modul mati.
            'upsellMap' => \App\Support\UpsellSuggestions::map($store),
        ]);
    }

    /**
     * Halaman status pesanan untuk pelanggan (guest).
     * URL: /m/{slug}/status/{statusToken}
     */
    public function status(Request $request, string $slug, string $statusToken)
    {
        $table = Table::where('slug', $slug)->with('store')->firstOrFail();
        $store = $table->store;

        if (! $store->is_active) {
            return Inertia::render('ScanMeja/Guest/StoreInactive');
        }

        if (! ScanMejaFeatures::enabled($store)) {
            return Inertia::render('ScanMeja/Guest/ModuleInactive');
        }

        if (! $table->is_active) {
            return Inertia::render('ScanMeja/Guest/TableInactive');
        }

        $transaction = Transaction::where('status_token', $statusToken)
            ->where('store_id', $store->id)
            ->where('payment_details->source', 'scan_meja')
            ->firstOrFail();

        $transaction->load('items');

        return Inertia::render('ScanMeja/Guest/OrderStatus', [
            'table' => [
                'id' => $table->id,
                'name' => $table->name,
                'slug' => $table->slug,
                'capacity' => $table->capacity,
            ],
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
                'phone' => $store->phone,
                ...\App\Support\ScanMejaAppearance::urls($store),
            ],
            // Detail metode bayar yang dipilih (gambar QRIS / rekening) agar pelanggan bisa membayar dari halaman ini.
            'paymentMethod' => self::paymentMethods($store)->firstWhere('type', $transaction->payment_method),
            'transaction' => [
                'id' => $transaction->id,
                'status_token' => $transaction->status_token,
                'number' => $transaction->transaction_number,
                'status' => $transaction->order_status,
                'payment_status' => $transaction->payment_status,
                'payment_method' => $transaction->payment_method,
                'subtotal' => $transaction->subtotal,
                'total_amount' => $transaction->total_amount,
                'notes' => $transaction->notes,
                'reject_reason' => self::rejectReason($transaction),
                'created_at' => $transaction->created_at->toISOString(),
                'items' => $transaction->items->map(fn ($i) => self::guestItem($i)),
            ],
        ]);
    }

    /**
     * Kategori berisi produk jual yang aktif, untuk halaman menu tamu
     * (dipakai juga oleh halaman pesan antar).
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public static function menuCategories(\App\Models\Store $store): \Illuminate\Support\Collection
    {
        return ProductCategory::where('store_id', $store->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function ($cat) {
                $products = $cat->products()
                    ->where('is_active', true)
                    ->where('inventory_type', 'sellable')
                    ->orderBy('name')
                    ->get()
                    ->map(fn ($p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'price' => (float) $p->price,
                        'description' => $p->description,
                        'image' => $p->image_url ?? null,
                        'has_variants' => $p->has_variants,
                        'modifier_options' => $p->modifier_options,
                    ]);

                return [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'products' => $products,
                ];
            })
            ->filter(fn ($cat) => count($cat['products']) > 0)
            ->values();
    }

    /**
     * Enam menu terlaris untuk jenis pesanan tertentu (dine_in / delivery), urut dari yang paling laku.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $categories
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public static function bestSelling(\App\Models\Store $store, \Illuminate\Support\Collection $categories, string $orderType): \Illuminate\Support\Collection
    {
        $bestSellingIds = TransactionItem::query()
            ->whereHas('transaction', fn ($query) => $query
                ->where('store_id', $store->id)
                ->where('status', 'completed')
                ->where('order_type', $orderType))
            ->whereNotNull('product_id')
            ->selectRaw('product_id, SUM(quantity) as total_quantity')
            ->groupBy('product_id')
            ->orderByDesc('total_quantity')
            ->limit(6)
            ->pluck('product_id');

        return collect($categories)
            ->flatMap(fn ($category) => $category['products'])
            ->filter(fn ($product) => $bestSellingIds->contains($product['id']))
            ->sortBy(fn ($product) => $bestSellingIds->search($product['id']))
            ->values();
    }

    /**
     * Metode pembayaran aktif dari settings toko.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public static function paymentMethods(\App\Models\Store $store): \Illuminate\Support\Collection
    {
        $rawMethods = $store->settings['scan_meja_payment_methods'] ?? [
            ['type' => 'cash', 'label' => 'Tunai', 'active' => true],
            ['type' => 'qris', 'label' => 'QRIS', 'active' => true],
        ];

        return collect($rawMethods)
            ->filter(fn ($m) => (bool) ($m['active'] ?? true))
            ->map(fn ($m) => [
                'type' => $m['type'],
                'label' => $m['label'],
                'instructions' => $m['instructions'] ?? null,
                'image_url' => ! empty($m['image']) ? asset('storage/'.$m['image']) : null,
                'bank_name' => $m['bank_name'] ?? null,
                'account_number' => $m['account_number'] ?? null,
                'account_name' => $m['account_name'] ?? null,
            ])
            ->values();
    }

    /**
     * Item pesanan untuk halaman tamu, termasuk catatan per item.
     *
     * @return array<string, mixed>
     */
    public static function guestItem(TransactionItem $item): array
    {
        $note = collect($item->modifiers ?? [])
            ->map(fn ($m) => is_array($m) ? ($m['value'] ?? $m['label'] ?? null) : null)
            ->filter()
            ->implode(', ');

        return [
            'name' => $item->product_name,
            'quantity' => (float) $item->quantity,
            'price' => (float) $item->unit_price,
            'total' => (float) $item->total_price,
            'note' => $note !== '' ? $note : null,
        ];
    }

    /** Alasan penolakan dari kasir (dicatat sebagai "[Ditolak kasir: ...]"). */
    public static function rejectReason(Transaction $transaction): ?string
    {
        if ($transaction->order_status !== 'cancelled') {
            return null;
        }

        return preg_match('/\[Ditolak kasir: (.*?)\]/s', (string) $transaction->notes, $m) ? trim($m[1]) : null;
    }
}
