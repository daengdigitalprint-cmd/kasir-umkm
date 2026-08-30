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

        // Ambil kategori produk yang memiliki produk aktif (tipe sellable)
        $categories = ProductCategory::where('store_id', $store->id)
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

        $bestSellingIds = TransactionItem::query()
            ->whereHas('transaction', fn ($query) => $query
                ->where('store_id', $store->id)
                ->where('status', 'completed')
                ->where('order_type', 'dine_in'))
            ->whereNotNull('product_id')
            ->selectRaw('product_id, SUM(quantity) as total_quantity')
            ->groupBy('product_id')
            ->orderByDesc('total_quantity')
            ->limit(6)
            ->pluck('product_id');

        $bestSelling = collect($categories)
            ->flatMap(fn ($category) => $category['products'])
            ->filter(fn ($product) => $bestSellingIds->contains($product['id']))
            ->sortBy(fn ($product) => $bestSellingIds->search($product['id']))
            ->values();

        // Metode pembayaran aktif dari settings toko
        $rawMethods = $store->settings['scan_meja_payment_methods'] ?? [
            ['type' => 'cash', 'label' => 'Tunai', 'active' => true],
            ['type' => 'qris', 'label' => 'QRIS', 'active' => true],
        ];
        $paymentMethods = collect($rawMethods)
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
            ],
            'categories' => $categories,
            'bestSelling' => $bestSelling,
            'paymentMethods' => $paymentMethods,
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
            ],
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
                'created_at' => $transaction->created_at->toISOString(),
                'items' => $transaction->items->map(fn ($i) => [
                    'name' => $i->product_name,
                    'quantity' => $i->quantity,
                    'price' => $i->unit_price,
                    'total' => $i->total_price,
                ]),
            ],
        ]);
    }
}
