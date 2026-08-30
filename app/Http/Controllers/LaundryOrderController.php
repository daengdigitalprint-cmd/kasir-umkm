<?php

namespace App\Http\Controllers;

use App\Models\CashShift;
use App\Models\Customer;
use App\Models\LaundryOrder;
use App\Models\LaundryOrderLine;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Support\LaundryFeatures;
use App\Support\StorePlanLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LaundryOrderController extends Controller
{
    /** Guard: pastikan toko ada & berjenis usaha laundry. */
    private function guardStore(): array
    {
        $store = auth()->user()->store;

        if (! $store) {
            return [null, redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.')];
        }

        if (! LaundryFeatures::enabled($store)) {
            return [null, redirect()->route('dashboard')
                ->with('warning', 'Fitur laundry hanya tersedia untuk toko berjenis usaha Laundry.')];
        }

        return [$store, null];
    }

    private function serializeOrder(LaundryOrder $o): array
    {
        return [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'status' => $o->status,
            'payment_status' => $o->payment_status,
            'customer_name' => $o->customer_name ?: $o->customer?->name,
            'customer_phone' => $o->customer_phone,
            'received_at' => $o->received_at?->toIso8601String(),
            'estimated_ready_at' => $o->estimated_ready_at?->toIso8601String(),
            'ready_at' => $o->ready_at?->toIso8601String(),
            'completed_at' => $o->completed_at?->toIso8601String(),
            'total_amount' => (float) $o->total_amount,
            'amount_paid' => (float) $o->amount_paid,
            'payment_method' => $o->payment_method,
            'notes' => $o->notes,
            'user_name' => $o->user?->name,
            'lines_count' => $o->lines_count ?? $o->lines->count(),
            'created_at' => $o->created_at?->toIso8601String(),
        ];
    }

    public function index()
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        $orders = $store->laundryOrders()
            ->with(['customer:id,name', 'user:id,name'])
            ->withCount('lines')
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (LaundryOrder $o) => $this->serializeOrder($o));

        return Inertia::render('Laundry/Index', [
            'orders' => $orders,
            'statuses' => LaundryOrder::STATUSES,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
        ]);
    }

    public function create()
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        $customers = $store->customers()->orderBy('name')->get(['id', 'name', 'phone']);

        $services = $store->products()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'price', 'allows_decimal']);

        return Inertia::render('Laundry/Create', [
            'customers' => $customers,
            'services' => $services,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
        ]);
    }

    public function store(Request $request)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:40',
            'estimated_ready_at' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'amount_paid' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|in:cash,card,ewallet,transfer,other',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|integer|exists:products,id',
            'lines.*.service_name' => 'required|string|max:255',
            'lines.*.unit' => 'nullable|string|max:20',
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();

        try {
            $customerId = $validated['customer_id'] ?? null;
            if ($customerId) {
                $c = Customer::query()->where('store_id', $store->id)->where('id', $customerId)->first();
                if (! $c) {
                    throw new \Exception('Pelanggan tidak valid.');
                }
            }

            $total = 0.0;
            $lineRows = [];

            foreach ($validated['lines'] as $row) {
                // Jika terkait produk, pastikan produk milik toko ini.
                if (! empty($row['product_id'])) {
                    $product = Product::query()
                        ->where('store_id', $store->id)
                        ->where('id', $row['product_id'])
                        ->first();
                    if (! $product) {
                        throw new \Exception('Layanan/produk tidak valid.');
                    }
                }

                $qty = round((float) $row['quantity'], 4);
                $unitPrice = round((float) $row['unit_price'], 2);
                $lineTotal = round($unitPrice * $qty, 2);
                $total += $lineTotal;

                $lineRows[] = [
                    'product_id' => $row['product_id'] ?? null,
                    'service_name' => $row['service_name'],
                    'unit' => $row['unit'] ?? null,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'notes' => $row['notes'] ?? null,
                ];
            }

            $total = round($total, 2);
            $amountPaid = round((float) ($validated['amount_paid'] ?? 0), 2);
            $amountPaid = min($amountPaid, $total);

            $order = LaundryOrder::create([
                'store_id' => $store->id,
                'customer_id' => $customerId,
                'user_id' => auth()->id(),
                'status' => 'received',
                'payment_status' => $this->derivePaymentStatus($total, $amountPaid),
                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'received_at' => now(),
                'estimated_ready_at' => $validated['estimated_ready_at'] ?? null,
                'total_amount' => $total,
                'amount_paid' => $amountPaid,
                'payment_method' => $amountPaid > 0 ? ($validated['payment_method'] ?? 'cash') : null,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($lineRows as $lr) {
                LaundryOrderLine::create([
                    'laundry_order_id' => $order->id,
                    ...$lr,
                ]);
            }

            DB::commit();

            return redirect()->route('laundry-orders.show', $order)->with('success', 'Order laundry disimpan.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }
    }

    public function show(LaundryOrder $laundryOrder)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        if ((int) $laundryOrder->store_id !== (int) $store->id) {
            abort(404);
        }

        $laundryOrder->load(['lines', 'customer:id,name,phone', 'user:id,name', 'transaction:id,transaction_number']);

        return Inertia::render('Laundry/Show', [
            'order' => [
                ...$this->serializeOrder($laundryOrder),
                'transaction' => $laundryOrder->transaction
                    ? ['id' => $laundryOrder->transaction->id, 'transaction_number' => $laundryOrder->transaction->transaction_number]
                    : null,
                'customer' => $laundryOrder->customer
                    ? ['id' => $laundryOrder->customer->id, 'name' => $laundryOrder->customer->name, 'phone' => $laundryOrder->customer->phone]
                    : null,
                'lines' => $laundryOrder->lines->map(fn (LaundryOrderLine $l) => [
                    'id' => $l->id,
                    'product_id' => $l->product_id,
                    'service_name' => $l->service_name,
                    'unit' => $l->unit,
                    'quantity' => (float) $l->quantity,
                    'unit_price' => (float) $l->unit_price,
                    'line_total' => (float) $l->line_total,
                    'notes' => $l->notes,
                ]),
            ],
            'statuses' => LaundryOrder::STATUSES,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
        ]);
    }

    public function updateStatus(Request $request, LaundryOrder $laundryOrder)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        if ((int) $laundryOrder->store_id !== (int) $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'status' => 'required|in:'.implode(',', LaundryOrder::STATUSES),
        ]);

        if (in_array($laundryOrder->status, ['completed', 'cancelled'], true)) {
            return back()->withErrors(['status' => 'Order sudah selesai atau dibatalkan, status tidak bisa diubah.']);
        }

        $newStatus = $validated['status'];
        $updates = ['status' => $newStatus];

        if ($newStatus === 'ready' && ! $laundryOrder->ready_at) {
            $updates['ready_at'] = now();
        }
        if ($newStatus === 'completed') {
            $updates['completed_at'] = now();
            if (! $laundryOrder->ready_at) {
                $updates['ready_at'] = now();
            }
        }

        DB::transaction(function () use ($laundryOrder, $updates, $newStatus, $store) {
            $laundryOrder->update($updates);

            // Akui pendapatan saat order selesai (diambil): buat satu transaksi penjualan
            // agar masuk ke laporan penjualan & arus kas, konsisten dengan alur POS.
            if ($newStatus === 'completed') {
                $this->createSalesTransaction($store, $laundryOrder->fresh(['lines']));
            }
        });

        return back()->with('success', 'Status order diperbarui menjadi '.$newStatus.'.');
    }

    /**
     * Buat transaksi penjualan dari order laundry yang selesai.
     * Idempoten: order yang sudah punya transaction_id tidak dibuatkan lagi.
     */
    private function createSalesTransaction(Store $store, LaundryOrder $laundryOrder): void
    {
        if ($laundryOrder->transaction_id) {
            return;
        }

        $total = round((float) $laundryOrder->total_amount, 2);
        if ($total <= 0) {
            return;
        }

        // Hormati batas transaksi bulanan paket Starter.
        StorePlanLimits::assertCanCreateTransaction($store);

        $paymentMethod = $laundryOrder->payment_method ?: 'cash';
        $amountPaid = round((float) $laundryOrder->amount_paid, 2);

        // Kaitkan ke shift kas yang terbuka untuk semua metode bayar,
        // agar ringkasan tunai maupun non-tunai per shift ikut terhitung.
        $cashShiftId = CashShift::query()
            ->where('store_id', $store->id)
            ->where('user_id', auth()->id())
            ->whereNull('closed_at')
            ->value('id');

        $transaction = Transaction::create([
            'store_id' => $store->id,
            'customer_id' => $laundryOrder->customer_id,
            'user_id' => auth()->id(),
            'cash_shift_id' => $cashShiftId,
            'status' => 'completed',
            'payment_method' => $paymentMethod,
            'order_type' => 'laundry',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $total,
            'amount_paid' => $amountPaid,
            'change_amount' => 0,
            'notes' => 'Order laundry '.$laundryOrder->order_number,
            'completed_at' => now(),
        ]);

        foreach ($laundryOrder->lines as $line) {
            TransactionItem::create([
                'transaction_id' => $transaction->id,
                'product_id' => $line->product_id,
                'product_name' => $line->service_name,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'total_price' => $line->line_total,
                'discount_amount' => 0,
                'tax_amount' => 0,
            ]);
        }

        $laundryOrder->update(['transaction_id' => $transaction->id]);
    }

    public function recordPayment(Request $request, LaundryOrder $laundryOrder)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        if ((int) $laundryOrder->store_id !== (int) $store->id) {
            abort(404);
        }

        if ($laundryOrder->status === 'cancelled') {
            return back()->withErrors(['amount' => 'Order dibatalkan, tidak bisa menerima pembayaran.']);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,card,ewallet,transfer,other',
        ]);

        $total = (float) $laundryOrder->total_amount;
        $newPaid = round((float) $laundryOrder->amount_paid + (float) $validated['amount'], 2);
        $newPaid = min($newPaid, $total);

        $laundryOrder->update([
            'amount_paid' => $newPaid,
            'payment_method' => $validated['payment_method'],
            'payment_status' => $this->derivePaymentStatus($total, $newPaid),
        ]);

        return back()->with('success', 'Pembayaran dicatat.');
    }

    public function cancel(LaundryOrder $laundryOrder)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        if ((int) $laundryOrder->store_id !== (int) $store->id) {
            abort(404);
        }

        if (in_array($laundryOrder->status, ['completed', 'cancelled'], true)) {
            return back()->withErrors(['status' => 'Order ini sudah tidak aktif.']);
        }

        $laundryOrder->update(['status' => 'cancelled']);

        return redirect()->route('laundry-orders.index')->with('success', 'Order laundry dibatalkan.');
    }

    private function derivePaymentStatus(float $total, float $paid): string
    {
        if ($paid <= 0) {
            return 'unpaid';
        }
        if ($paid + 0.009 < $total) {
            return 'partial';
        }

        return 'paid';
    }
}
