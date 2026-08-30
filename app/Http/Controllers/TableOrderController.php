<?php

namespace App\Http\Controllers;

use App\Models\CashShift;
use App\Models\QueueEntry;
use App\Models\Transaction;
use App\Support\ScanMejaFeatures;
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
            $query->whereIn('order_status', ['accepted', 'ready']);
        } elseif (in_array($statusFilter, ['pending', 'ready', 'completed', 'cancelled'], true)) {
            $query->where('order_status', $statusFilter);
        }

        $orders = $query
            ->latest()
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
            'accepted' => Transaction::where('store_id', $store->id)
                ->where('order_type', 'dine_in')
                ->whereIn('order_status', ['accepted', 'ready'])
                ->where(function ($q) {
                    $q->whereJsonContains('payment_details->source', 'scan_meja')
                        ->orWhere('payment_details->source', 'scan_meja');
                })
                ->count(),
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

        return Inertia::render('TableOrders/Index', [
            'orders' => $orders->through(function (Transaction $t) {
                return [
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
            return response()->json(['message' => 'Pesanan sudah diproses.'], 422);
        }

        $transaction->update([
            'order_status' => 'accepted',
            'accepted_at' => now(),
            'user_id' => auth()->id(),
        ]);

        if ($store->settings['kitchen_queue_enabled'] ?? false) {
            $queue = $store->queues()->where('is_active', true)->first();
            if (! $queue) {
                $queue = $store->queues()->create(['name' => 'Kasir', 'code' => 'Q001', 'is_active' => true]);
            }

            QueueEntry::firstOrCreate(
                ['transaction_id' => $transaction->id],
                [
                    'queue_id' => $queue->id,
                    'store_id' => $store->id,
                    'number' => ((int) $queue->entries()->max('number')) + 1,
                    'status' => 'waiting',
                    'order_type' => 'dine_in',
                    'table_number' => $transaction->table_reference,
                    'items' => $transaction->items->map(fn ($item) => [
                        'product_name' => $item->product_name,
                        'quantity' => (int) $item->quantity,
                    ])->values()->all(),
                ],
            );
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
            return response()->json(['message' => 'Pesanan sudah dibayar.'], 422);
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:cash,qris,non_cash,card,ewallet,transfer,other,bank_transfer',
            'payment_reference' => 'nullable|string|max:255',
        ]);
        $shift = CashShift::query()->where('store_id', $store->id)->where('user_id', auth()->id())->whereNull('closed_at')->first();
        if (! $shift) {
            return response()->json(['message' => 'Buka shift kasir terlebih dahulu.'], 422);
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

        QueueEntry::where('transaction_id', $transaction->id)->update(['status' => 'completed']);

        return redirect()->back()->with('success', 'Pembayaran pesanan berhasil.');
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
            return response()->json(['message' => 'Pesanan belum siap diselesaikan.'], 422);
        }
        if ($transaction->payment_status !== 'paid') {
            return response()->json(['message' => 'Pesanan harus dibayar sebelum diselesaikan.'], 422);
        }
        $transaction->update(['order_status' => 'completed', 'status' => 'completed', 'completed_at' => now()]);
        QueueEntry::where('transaction_id', $transaction->id)->update(['status' => 'completed']);

        return redirect()->back()->with('success', 'Pesanan ditandai selesai.');
    }

    public function reject(Request $request, Transaction $transaction)
    {
        $store = auth()->user()->store;

        if (! $store || $transaction->store_id !== $store->id) {
            abort(403);
        }

        if ($transaction->order_status !== 'pending') {
            return response()->json(['message' => 'Pesanan sudah diproses.'], 422);
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

        return redirect()->back()->with('success', 'Pesanan ditolak.');
    }
}
