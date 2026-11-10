<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\ReceivablePayment;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Manajemen piutang pelanggan (accounts receivable).
 *
 * Index dikelompokkan per pelanggan (customer-level ledger),
 * lalu detail per pelanggan menampilkan daftar utang + riwayat pembayaran.
 */
class ReceivablesController extends Controller
{
    /**
     * Daftar pelanggan yang memiliki piutang, diagregat per pelanggan.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create');
        }

        $filter = $request->input('status', 'outstanding'); // outstanding | all
        $search = trim((string) $request->input('search', ''));
        $now = now();

        $query = Transaction::where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereNotNull('customer_id');

        if ($filter === 'outstanding') {
            $query->whereIn('payment_status', ['partial', 'unpaid']);
        } else {
            // Semua pelanggan yang pernah berutang (masih aktif atau sudah lunas).
            $query->where(function ($q) {
                $q->whereIn('payment_status', ['partial', 'unpaid'])
                    ->orWhereNotNull('debt_due_at');
            });
        }

        if ($search !== '') {
            $query->whereHas('customer', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $rows = $query
            ->groupBy('customer_id')
            ->selectRaw('customer_id')
            ->selectRaw('SUM(total_amount - amount_paid) as outstanding_total')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('MIN(debt_due_at) as earliest_due_at')
            ->selectRaw('SUM(CASE WHEN debt_due_at IS NOT NULL AND debt_due_at < ? THEN 1 ELSE 0 END) as overdue_count', [$now])
            ->orderByRaw('SUM(CASE WHEN debt_due_at IS NOT NULL AND debt_due_at < ? THEN 1 ELSE 0 END) DESC', [$now])
            ->orderByRaw('SUM(total_amount - amount_paid) DESC')
            ->paginate(15)
            ->withQueryString();

        $customers = Customer::whereIn('id', $rows->pluck('customer_id'))
            ->get()
            ->keyBy('id');

        $rows->through(function ($row) use ($customers) {
            $customer = $customers->get($row->customer_id);

            return [
                'customer' => $customer ? [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                ] : null,
                'outstanding_total' => round((float) $row->outstanding_total, 2),
                'transaction_count' => (int) $row->transaction_count,
                'earliest_due_at' => $row->earliest_due_at ? Carbon::parse($row->earliest_due_at)->toDateString() : null,
                'overdue_count' => (int) $row->overdue_count,
            ];
        });

        // Summary
        $outstandingTotal = Transaction::where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereNotNull('customer_id')
            ->whereIn('payment_status', ['partial', 'unpaid'])
            ->selectRaw('SUM(total_amount - amount_paid) as total')
            ->value('total') ?? 0;

        $overdueCount = Transaction::where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereNotNull('customer_id')
            ->whereIn('payment_status', ['partial', 'unpaid'])
            ->where('debt_due_at', '<', now())
            ->count();

        return Inertia::render('Receivables/Index', [
            'customers' => $rows,
            'filter' => $filter,
            'search' => $search,
            'outstandingTotal' => round((float) $outstandingTotal, 2),
            'overdueCount' => $overdueCount,
            'store' => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'IDR'],
        ]);
    }

    /**
     * Detail piutang satu pelanggan: ringkasan, daftar utang, riwayat pembayaran.
     */
    public function showCustomer(Request $request, string $customer)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create');
        }

        $customer = Customer::where('store_id', $store->id)->findOrFail($customer);

        // Semua transaksi utang pelanggan ini (aktif maupun yang sudah lunas).
        $transactions = Transaction::where('store_id', $store->id)
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->whereIn('payment_status', ['partial', 'unpaid'])
                    ->orWhereNotNull('debt_due_at');
            })
            ->orderBy('created_at', 'desc')
            ->get();

        $active = $transactions->whereIn('payment_status', ['partial', 'unpaid']);

        $summary = [
            'total_debt' => round($transactions->sum(fn ($t) => (float) $t->total_amount), 2),
            'total_paid' => round($transactions->sum(fn ($t) => (float) $t->amount_paid), 2),
            'outstanding_total' => round($active->sum(fn ($t) => (float) $t->total_amount - (float) $t->amount_paid), 2),
            'active_count' => $active->count(),
            'overdue_count' => $active->filter(fn ($t) => $t->debt_due_at && $t->debt_due_at->isPast())->count(),
        ];

        $payments = ReceivablePayment::where('store_id', $store->id)
            ->where('customer_id', $customer->id)
            ->with(['user:id,name', 'transaction:id,transaction_number'])
            ->orderByDesc('received_at')
            ->paginate(20, ['*'], 'payments_page')
            ->withQueryString()
            ->through(fn ($p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'payment_method' => $p->payment_method,
                'received_at' => $p->received_at->toDateTimeString(),
                'reference_number' => $p->reference_number,
                'notes' => $p->notes,
                'user_name' => $p->user?->name,
                'transaction_number' => $p->transaction?->transaction_number,
                'transaction_id' => $p->transaction_id,
            ]);

        return Inertia::render('Receivables/CustomerDetail', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'address' => $customer->address,
            ],
            'summary' => $summary,
            'transactions' => $transactions->map(fn ($t) => [
                'id' => $t->id,
                'transaction_number' => $t->transaction_number,
                'total_amount' => (float) $t->total_amount,
                'amount_paid' => (float) $t->amount_paid,
                'outstanding' => round((float) $t->total_amount - (float) $t->amount_paid, 2),
                'payment_status' => $t->payment_status,
                'debt_due_at' => $t->debt_due_at?->toDateString(),
                'created_at' => $t->created_at->toDateTimeString(),
                'is_overdue' => $t->debt_due_at && $t->debt_due_at->isPast() && $t->payment_status !== 'paid',
            ])->values(),
            'payments' => $payments,
            'store' => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'IDR'],
        ]);
    }

    /**
     * Detail piutang satu transaksi + riwayat pembayaran.
     */
    public function show(string $id)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create');
        }

        $transaction = Transaction::where('store_id', $store->id)
            ->where('id', $id)
            ->where('status', 'completed')
            ->whereNotNull('customer_id')
            ->with([
                'customer:id,name,phone,email,address',
                'items:id,transaction_id,product_name,quantity,unit_price,total_price',
                'receivablePayments' => fn ($q) => $q->orderBy('received_at')->with('user:id,name'),
            ])
            ->firstOrFail();

        return Inertia::render('Receivables/Show', [
            'transaction' => [
                'id' => $transaction->id,
                'transaction_number' => $transaction->transaction_number,
                'total_amount' => (float) $transaction->total_amount,
                'amount_paid' => (float) $transaction->amount_paid,
                'outstanding' => round((float) $transaction->total_amount - (float) $transaction->amount_paid, 2),
                'payment_status' => $transaction->payment_status,
                'payment_method' => $transaction->payment_method,
                'debt_due_at' => $transaction->debt_due_at?->toDateString(),
                'created_at' => $transaction->created_at->toDateTimeString(),
                'is_overdue' => $transaction->debt_due_at && $transaction->debt_due_at->isPast() && $transaction->payment_status !== 'paid',
                'customer' => $transaction->customer ? [
                    'id' => $transaction->customer->id,
                    'name' => $transaction->customer->name,
                    'phone' => $transaction->customer->phone,
                    'email' => $transaction->customer->email,
                    'address' => $transaction->customer->address,
                ] : null,
                'items' => $transaction->items->map(fn ($i) => [
                    'product_name' => $i->product_name,
                    'quantity' => $i->quantity,
                    'unit_price' => (float) $i->unit_price,
                    'subtotal' => (float) $i->total_price,
                ])->values(),
                'payments' => $transaction->receivablePayments->map(fn ($p) => [
                    'id' => $p->id,
                    'amount' => (float) $p->amount,
                    'payment_method' => $p->payment_method,
                    'received_at' => $p->received_at->toDateTimeString(),
                    'reference_number' => $p->reference_number,
                    'notes' => $p->notes,
                    'user_name' => $p->user?->name,
                ])->values(),
            ],
            'store' => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'IDR'],
        ]);
    }
}
