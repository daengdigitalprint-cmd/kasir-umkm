<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\ReceivablePayment;
use App\Models\Transaction;
use App\Support\Accounting\AccountingPeriodGuard;
use App\Support\ReceivableCollector;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
     *
     * status: outstanding (belum lunas) | overdue (ada yang lewat tempo) | due_soon (jatuh tempo ≤ 7 hari) | paid (sudah lunas)
     * sort:   due (lewat tempo & tempo terdekat dulu) | amount (sisa terbesar) | name
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create');
        }

        $filter = in_array($request->input('status'), ['outstanding', 'overdue', 'due_soon', 'paid'], true)
            ? $request->input('status')
            : 'outstanding';
        $sort = in_array($request->input('sort'), ['due', 'amount', 'name'], true) ? $request->input('sort') : 'due';
        $search = trim((string) $request->input('search', ''));
        $now = now();
        $soon = now()->addDays(7)->endOfDay();

        $query = Transaction::where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereNotNull('customer_id');

        if ($filter === 'paid') {
            $query->where('payment_status', 'paid')->where(function ($q) {
                $q->whereNotNull('debt_due_at')
                    ->orWhere('payment_method', 'credit')
                    ->orWhereHas('receivablePayments');
            });
        } else {
            $query->whereIn('payment_status', ['partial', 'unpaid']);
        }

        if ($search !== '') {
            $query->whereHas('customer', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $overdueExpr = 'SUM(CASE WHEN debt_due_at IS NOT NULL AND debt_due_at < ? THEN 1 ELSE 0 END)';
        $query->groupBy('customer_id')
            ->selectRaw('customer_id')
            ->selectRaw('SUM(total_amount - amount_paid) as outstanding_total')
            ->selectRaw('SUM(total_amount) as debt_total')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('MIN(debt_due_at) as earliest_due_at')
            ->selectRaw("{$overdueExpr} as overdue_count", [$now]);

        if ($filter === 'overdue') {
            $query->havingRaw("{$overdueExpr} > 0", [$now]);
        } elseif ($filter === 'due_soon') {
            $query->havingRaw('SUM(CASE WHEN debt_due_at >= ? AND debt_due_at <= ? THEN 1 ELSE 0 END) > 0', [$now, $soon]);
        }

        match ($sort) {
            'amount' => $query->orderByRaw('SUM(total_amount - amount_paid) DESC'),
            'name' => $query->orderBy(Customer::select('name')->whereColumn('customers.id', 'transactions.customer_id')),
            default => $query->orderByRaw("{$overdueExpr} DESC", [$now])
                ->orderByRaw('CASE WHEN MIN(debt_due_at) IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw('MIN(debt_due_at) ASC')
                ->orderByRaw('SUM(total_amount - amount_paid) DESC'),
        };

        $rows = $query->paginate(15)->withQueryString();

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
                'debt_total' => round((float) $row->debt_total, 2),
                'transaction_count' => (int) $row->transaction_count,
                'earliest_due_at' => $row->earliest_due_at ? Carbon::parse($row->earliest_due_at)->toDateString() : null,
                'overdue_count' => (int) $row->overdue_count,
            ];
        });

        // Ringkasan seluruh piutang aktif toko.
        $active = Transaction::where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereNotNull('customer_id')
            ->whereIn('payment_status', ['partial', 'unpaid']);
        $remainingExpr = DB::raw('total_amount - amount_paid');

        $summary = [
            'outstanding_total' => round((float) (clone $active)->sum($remainingExpr), 2),
            'customer_count' => (clone $active)->distinct()->count('customer_id'),
            'overdue_total' => round((float) (clone $active)->where('debt_due_at', '<', $now)->sum($remainingExpr), 2),
            'overdue_customers' => (clone $active)->where('debt_due_at', '<', $now)->distinct()->count('customer_id'),
            'due_soon_total' => round((float) (clone $active)->whereBetween('debt_due_at', [$now, $soon])->sum($remainingExpr), 2),
            'due_soon_customers' => (clone $active)->whereBetween('debt_due_at', [$now, $soon])->distinct()->count('customer_id'),
        ];

        return Inertia::render('Receivables/Index', [
            'customers' => $rows,
            'filter' => $filter,
            'sort' => $sort,
            'search' => $search,
            'summary' => $summary,
            'store' => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'IDR'],
        ]);
    }

    /**
     * Terima pembayaran sekaligus dari satu pelanggan; dialokasikan ke bon
     * dengan jatuh tempo paling awal (lalu bon tertua) sampai uangnya habis.
     */
    public function collectForCustomer(Request $request, string $customer)
    {
        $user = auth()->user();
        $store = $user->store;
        abort_if(! $store, 403, 'Tidak ada toko aktif.');

        $customer = Customer::where('store_id', $store->id)->findOrFail($customer);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1|max:99999999999.99',
            'payment_method' => 'required|string|in:'.implode(',', ReceivableCollector::METHODS),
            'received_at' => 'required|date',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ], [
            'amount.required' => 'Isi jumlah yang dibayar.',
            'amount.min' => 'Jumlah bayar minimal Rp 1.',
        ]);

        AccountingPeriodGuard::assertDateOpen($store, $validated['received_at']);

        $result = DB::transaction(function () use ($store, $user, $customer, $validated) {
            $transactions = Transaction::where('store_id', $store->id)
                ->where('customer_id', $customer->id)
                ->where('status', 'completed')
                ->whereIn('payment_status', ['partial', 'unpaid'])
                ->orderByRaw('CASE WHEN debt_due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('debt_due_at')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $remaining = round((float) $validated['amount'], 2);
            $applied = 0.0;
            $settled = 0;
            foreach ($transactions as $transaction) {
                if ($remaining <= 0) {
                    break;
                }
                $payment = ReceivableCollector::apply($store, $user, $transaction, $remaining, $validated);
                if (! $payment) {
                    continue;
                }
                $remaining = round($remaining - (float) $payment->amount, 2);
                $applied = round($applied + (float) $payment->amount, 2);
                if ($transaction->payment_status === 'paid') {
                    $settled++;
                }
            }

            return ['applied' => $applied, 'settled' => $settled];
        });

        if ($result['applied'] <= 0) {
            return back()->with('error', 'Pelanggan ini tidak punya utang yang belum lunas.');
        }

        $rupiah = fn (float $n) => 'Rp '.number_format($n, 0, ',', '.');
        $message = 'Pembayaran '.$rupiah($result['applied']).' dicatat';
        $message .= $result['settled'] > 0 ? ", {$result['settled']} bon lunas." : '.';
        if ($result['applied'] < (float) $validated['amount']) {
            $message .= ' Kelebihan '.$rupiah((float) $validated['amount'] - $result['applied']).' tidak dicatat karena semua utang sudah lunas.';
        }

        return back()->with('success', $message);
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
                // Termasuk bon utang yang sudah lunas, walau tanpa tanggal jatuh tempo.
                $q->whereIn('payment_status', ['partial', 'unpaid'])
                    ->orWhereNotNull('debt_due_at')
                    ->orWhere('payment_method', 'credit')
                    ->orWhereHas('receivablePayments');
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
