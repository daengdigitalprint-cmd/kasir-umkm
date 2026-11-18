<?php

namespace App\Http\Controllers;

use App\Events\KitchenQueueUpdated;
use App\Models\CashShift;
use App\Models\QueueEntry;
use App\Models\Transaction;
use App\Support\ScanMejaFeatures;
use App\Support\TableOrders;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TableOrderController extends Controller
{
    /**
     * Daftar pesanan masuk dari Scan Meja (status pending, order_type dine_in,
     * berasal dari payment_details.source = scan_meja).
     *
     * Best practice aplikasi kasir Indonesia (Moka, iSeller, Pawoon, Olsera):
     * - Pesanan tamu tampil di panel terpisah dari riwayat transaksi kasir
     * - Kasir bisa terima (proses pembayaran) atau tolak pesanan
     * - Badge notifikasi menampilkan jumlah pesanan belum diproses
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! ScanMejaFeatures::enabled($store)) {
            return redirect()->route('dashboard')
                ->with('error', 'Modul Scan Meja tidak aktif.');
        }

        $statusFilter = $request->input('status', 'pending');

        $query = Transaction::query()
            ->where('store_id', $store->id)
            ->where('order_type', 'dine_in')
            ->where(function ($q) {
                $q->whereJsonContains('payment_details->source', 'scan_meja')
                    ->orWhere('payment_details->source', 'scan_meja');
            })
            ->with([
                'items:id,transaction_id,product_name,quantity,unit_price,total_price,modifiers,product_snapshot',
            ]);

        if ($statusFilter === 'accepted') {
            $query->whereIn('order_status', TableOrders::UNPAID_STATUSES)->where('payment_status', '!=', 'paid');
        } elseif (in_array($statusFilter, ['pending', 'ready', 'completed', 'cancelled'], true)) {
            $query->where('order_status', $statusFilter);
        }

        $orders = ($statusFilter === 'accepted' ? $query->oldest() : $query->latest())
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'active' => Transaction::where('store_id', $store->id)
                ->where('order_type', 'dine_in')
                ->whereIn('order_status', ['pending', 'accepted', 'serving', 'ready'])
                ->where(function ($q) {
                    $q->whereJsonContains('payment_details->source', 'scan_meja')
                        ->orWhere('payment_details->source', 'scan_meja');
                })
                ->count(),
            'pending' => Transaction::where('store_id', $store->id)
                ->where('order_type', 'dine_in')
                ->where('order_status', 'pending')
                ->where(function ($q) {
                    $q->whereJsonContains('payment_details->source', 'scan_meja')
                        ->orWhere('payment_details->source', 'scan_meja');
                })
                ->count(),
            'accepted' => TableOrders::unpaidCount($store->id),
            'serving' => Transaction::where('store_id', $store->id)->where('order_type', 'dine_in')->where('order_status', 'serving')->where('payment_details->source', 'scan_meja')->count(),
            'ready' => Transaction::where('store_id', $store->id)->where('order_type', 'dine_in')->where('order_status', 'ready')->where('payment_details->source', 'scan_meja')->count(),
            'completed' => Transaction::where('store_id', $store->id)
                ->where('order_type', 'dine_in')
                ->where('order_status', 'completed')
                ->where(function ($q) {
                    $q->whereJsonContains('payment_details->source', 'scan_meja')
                        ->orWhere('payment_details->source', 'scan_meja');
                })
                ->whereDate('created_at', today())
                ->count(),
            'cancelled' => Transaction::where('store_id', $store->id)
                ->where('order_type', 'dine_in')
                ->where('order_status', 'cancelled')
                ->where(function ($q) {
                    $q->whereJsonContains('payment_details->source', 'scan_meja')
                        ->orWhere('payment_details->source', 'scan_meja');
                })
                ->count(),
        ];

        $kitchen = TableOrders::kitchenStatuses($orders->getCollection()->pluck('id'));

        return Inertia::render('TableOrders/Index', [
            'orders' => $orders->through(function (Transaction $t) use ($kitchen) {
                return [
                    'kitchen_status' => $kitchen->get($t->id),
                    'id' => $t->id,
                    'transaction_number' => $t->transaction_number,
                    'status' => $t->order_status,
                    'payment_status' => $t->payment_status,
                    'order_type' => $t->order_type,
                    'table_reference' => $t->table_reference,
                    'payment_method' => $t->payment_method,
                    'subtotal' => (float) $t->subtotal,
                    'total_amount' => (float) $t->total_amount,
                    'notes' => $t->notes,
                    'payment_details' => $t->payment_details,
                    'created_at' => $t->created_at?->toIso8601String(),
                    'completed_at' => $t->completed_at?->toIso8601String(),
                    'items' => $t->items->map(fn ($i) => [
                        'id' => $i->id,
                        'product_name' => $i->product_name,
                        'quantity' => (float) $i->quantity,
                        'unit_price' => (float) $i->unit_price,
                        'total_price' => (float) $i->total_price,
                        'modifiers' => $i->modifiers,
                    ])->values()->all(),
                ];
            }),
            'stats' => $stats,
            'statusFilter' => $statusFilter,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
        ]);
    }

    /**
     * Terima pesanan: set status ke 'completed' (pembayaran tunai di kasir)
     * atau redirect ke POS untuk proses pembayaran.
     *
     * Best practice: kasir konfirmasi pesanan diterima, lalu proses pembayaran.
     * Untuk simpel: konfirmasi = tandai completed (pembayaran tunai).
     */
    public function accept(Request $request, Transaction $transaction)
    {
        $store = auth()->user()->store;

        if (! $store || $transaction->store_id !== $store->id) {
            abort(403);
        }

        if ($transaction->order_status !== 'pending') {
            return back()->with('error', 'Pesanan sudah diproses.');
        }

        $transaction->update([
            'order_status' => 'accepted',
            'accepted_at' => now(),
            'user_id' => auth()->id(),
        ]);

        // Hanya kirim ke layar dapur bila modul Antrian Dapur (atau antrean lama) aktif.
        if (! \App\Support\KitchenOrders::shouldEnqueue($store)) {
            return redirect()->back()->with(
                'success',
                'Pesanan diterima. Papan dapur belum aktif, jadi pesanan tidak muncul di layar dapur — cetak KOT atau aktifkan Antrian Dapur di Pengaturan.',
            );
        }

        $entry = \App\Support\KitchenOrders::enqueue($store, $transaction, [
            'order_type' => 'dine_in',
            'table_number' => $transaction->table_reference,
            'customer_name' => $transaction->payment_details['customer_name'] ?? null,
        ]);

        if (! $entry) {
            return redirect()->back()->with('warning', 'Pesanan diterima, tetapi gagal dikirim ke layar dapur. Cetak KOT sebagai cadangan.');
        }

        return redirect()->back()->with('success', 'Pesanan diterima dan diteruskan ke dapur.');
    }

    public function pay(Request $request, Transaction $transaction)
    {
        $store = auth()->user()->store;
        if (! $store || $transaction->store_id !== $store->id) {
            abort(403);
        }
        if ($transaction->order_type !== 'dine_in' || ($transaction->payment_details['source'] ?? null) !== 'scan_meja') {
            abort(404);
        }
        if ($transaction->payment_status === 'paid') {
            return $this->payResult($request, false, 'Pesanan sudah dibayar.');
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:cash,qris,non_cash,card,ewallet,transfer,other,bank_transfer',
            'payment_reference' => 'nullable|string|max:255',
        ]);
        $shift = CashShift::query()->where('store_id', $store->id)->where('user_id', auth()->id())->whereNull('closed_at')->first();
        if (! $shift) {
            return $this->payResult($request, false, 'Buka shift kasir terlebih dahulu.');
        }

        $details = $transaction->payment_details ?? [];
        $details['source'] = 'scan_meja';
        $details['paid_by'] = auth()->id();
        $details['paid_at'] = now()->toIso8601String();
        if ($validated['payment_reference'] ?? null) {
            $details['payment_reference'] = $validated['payment_reference'];
        }

        $transaction->update([
            'payment_method' => $validated['payment_method'],
            'payment_status' => 'paid',
            'status' => 'completed',
            'order_status' => 'completed',
            'amount_paid' => $transaction->total_amount,
            'change_amount' => 0,
            'cash_shift_id' => $shift->id,
            'payment_details' => $details,
            'completed_at' => now(),
            'user_id' => auth()->id(),
        ]);

        $queueEntries = QueueEntry::where('transaction_id', $transaction->id)->get();
        foreach ($queueEntries as $entry) {
            $entry->update(['status' => 'completed']);
            \App\Support\KitchenOrders::broadcast(KitchenQueueUpdated::fromEntry($entry->load('transaction:id,transaction_number,order_type,table_reference')));
        }

        return $this->payResult($request, true, 'Pembayaran '.($transaction->table_reference ?: 'pesanan meja').' berhasil dicatat.');
    }

    /**
     * Daftar pesanan meja yang sudah diterima tetapi belum dibayar (untuk panel Open Bill di POS).
     */
    public function unpaid(Request $request)
    {
        $store = auth()->user()?->store;

        if (! $store || ! ScanMejaFeatures::enabled($store)) {
            return response()->json(['success' => true, 'orders' => []]);
        }

        $orders = TableOrders::unpaidQuery($store->id)
            ->with('items:id,transaction_id,product_name,quantity')
            ->oldest()
            ->limit(50)
            ->get();
        $kitchen = TableOrders::kitchenStatuses($orders->pluck('id'));

        return response()->json([
            'success' => true,
            'orders' => $orders->map(fn (Transaction $t) => [
                'id' => $t->id,
                'transaction_number' => $t->transaction_number,
                'table_reference' => $t->table_reference,
                'customer_name' => $t->payment_details['customer_name'] ?? null,
                'payment_method' => $t->payment_method,
                'total_amount' => (float) $t->total_amount,
                'items_count' => (float) $t->items->sum('quantity'),
                'items' => $t->items->map(fn ($i) => ['product_name' => $i->product_name, 'quantity' => (float) $i->quantity])->values(),
                'kitchen_status' => $kitchen->get($t->id),
                'created_at' => $t->created_at?->toIso8601String(),
                'elapsed_minutes' => (int) abs(now()->diffInMinutes($t->created_at)),
            ])->values(),
        ]);
    }

    /** Hasil bayar: JSON bila dipanggil dari POS, redirect + flash bila dari halaman Pesanan Masuk. */
    private function payResult(Request $request, bool $ok, string $message)
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => $ok, 'message' => $message], $ok ? 200 : 422);
        }

        return back()->with($ok ? 'success' : 'error', $message);
    }

    /**
     * Tolak / batalkan pesanan dari meja.
     */
    public function complete(Request $request, Transaction $transaction)
    {
        $store = auth()->user()->store;
        if (! $store || $transaction->store_id !== $store->id) {
            abort(403);
        }
        if ($transaction->order_status !== 'accepted' && $transaction->order_status !== 'ready') {
            return back()->with('error', 'Pesanan belum siap diselesaikan.');
        }
        if ($transaction->payment_status !== 'paid') {
            return back()->with('error', 'Pesanan harus dibayar sebelum diselesaikan.');
        }
        $transaction->update(['order_status' => 'completed', 'status' => 'completed', 'completed_at' => now()]);

        $queueEntries = QueueEntry::where('transaction_id', $transaction->id)->get();
        foreach ($queueEntries as $entry) {
            $entry->update(['status' => 'completed']);
            \App\Support\KitchenOrders::broadcast(KitchenQueueUpdated::fromEntry($entry->load('transaction:id,transaction_number,order_type,table_reference')));
        }

        return redirect()->back()->with('success', 'Pesanan ditandai selesai.');
    }

    public function reject(Request $request, Transaction $transaction)
    {
        $store = auth()->user()->store;

        if (! $store || $transaction->store_id !== $store->id) {
            abort(403);
        }

        if ($transaction->order_status !== 'pending') {
            return back()->with('error', 'Pesanan sudah diproses.');
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $transaction->update([
            'order_status' => 'cancelled',
            'status' => 'cancelled',
            'notes' => $transaction->notes."\n[Ditolak kasir: ".($validated['reason'] ?? 'Tidak ada alasan').']',
            'user_id' => auth()->id(),
        ]);

        $queueEntries = QueueEntry::where('transaction_id', $transaction->id)->get();
        foreach ($queueEntries as $entry) {
            $entry->update(['status' => 'cancelled']);
            \App\Support\KitchenOrders::broadcast(KitchenQueueUpdated::fromEntry($entry->load('transaction:id,transaction_number,order_type,table_reference')));
        }

        return redirect()->back()->with('success', 'Pesanan ditolak.');
    }
}
