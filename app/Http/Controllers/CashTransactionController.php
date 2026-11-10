<?php

namespace App\Http\Controllers;

use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Accounting\JournalPoster;
use App\Support\StorePlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CashTransactionController extends Controller
{
    /**
     * Tampilkan halaman kas masuk/keluar pada shift yang aktif.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $user = Auth::user();
        $store = $user->store;

        if (! $store) {
            abort(403, 'Tidak ada toko aktif.');
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()
                ->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup shift kasir.');
        }

        $openShift = $this->resolveOpenShift($request, $store, $user);

        if (! $openShift) {
            return redirect()
                ->route('cash-shifts.index')
                ->with('error', 'Anda harus membuka kasir terlebih dahulu.');
        }

        $summary = $this->buildShiftSummary($openShift);
        $transactions = $this->serializeTransactions(
            CashTransaction::query()
                ->where('store_id', $store->id)
                ->where('cash_shift_id', $openShift->id)
                ->with('user:id,name')
                ->latest()
                ->get()
        );

        return Inertia::render('CashTransactions/Index', [
            'transactions' => $transactions,
            'shift' => [
                'id' => $openShift->id,
                'shift_number' => $openShift->shift_number,
                'opening_cash' => $summary['opening_cash'],
                'expected_cash' => $summary['expected_cash'],
            ],
            'summary' => $summary,
            'canViewReport' => $user->canAccessBackOffice(),
        ]);
    }

    /**
     * Laporan kas masuk/keluar (back office).
     */
    public function report(Request $request): Response
    {
        $user = Auth::user();
        $store = $user->store;

        if (! $store) {
            abort(403, 'Tidak ada toko aktif.');
        }

        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));
        $type = $request->get('type');
        $category = $request->get('category');
        $userId = $request->get('user_id') ? (int) $request->get('user_id') : null;

        $entries = $this->buildReportEntries($store->id, $startDate, $endDate, $type ?: null, $category ?: null, $userId);

        $posCashSales = (float) $entries
            ->where('source', 'pos')
            ->sum(fn (array $row) => (float) $row['amount']);

        $manualIn = (float) $entries
            ->where('source', 'manual')
            ->where('type', CashTransaction::TYPE_IN)
            ->sum(fn (array $row) => (float) $row['amount']);

        $manualOut = (float) $entries
            ->where('source', 'manual')
            ->where('type', CashTransaction::TYPE_OUT)
            ->sum(fn (array $row) => (float) $row['amount']);

        $totalIn = $posCashSales + $manualIn;

        $perPage = 50;
        $page = max(1, (int) $request->get('page', 1));
        $paginated = new LengthAwarePaginator(
            $entries->forPage($page, $perPage)->values(),
            $entries->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $cashiers = User::query()
            ->whereHas('stores', fn ($q) => $q->where('stores.id', $store->id))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name])
            ->values()
            ->all();

        return Inertia::render('Reports/CashTransactions', [
            'transactions' => $paginated,
            'summary' => [
                'pos_cash_sales' => $posCashSales,
                'manual_in' => $manualIn,
                'manual_out' => $manualOut,
                'total_in' => $totalIn,
                'total_out' => $manualOut,
                'net' => round($totalIn - $manualOut, 2),
                'count' => $entries->count(),
            ],
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'type' => $type ?: '',
                'category' => $category ?: '',
                'user_id' => $userId ?: '',
            ],
            'cashiers' => $cashiers,
            'categoryOptions' => [
                'pos_cash' => 'Penjualan Tunai (POS)',
                CashTransaction::CATEGORY_REVENUE => CashTransaction::categoryLabel(CashTransaction::CATEGORY_REVENUE),
                CashTransaction::CATEGORY_NON_REVENUE => CashTransaction::categoryLabel(CashTransaction::CATEGORY_NON_REVENUE),
                CashTransaction::CATEGORY_EXPENSE => CashTransaction::categoryLabel(CashTransaction::CATEGORY_EXPENSE),
                CashTransaction::CATEGORY_NON_EXPENSE => CashTransaction::categoryLabel(CashTransaction::CATEGORY_NON_EXPENSE),
            ],
        ]);
    }

    /**
     * Simpan uang masuk atau keluar ke shift aktif.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $store = $user->store;

        if (! $store) {
            abort(403, 'Tidak ada toko aktif.');
        }

        $openShift = $this->resolveOpenShift($request, $store, $user);

        if (! $openShift) {
            return back()->with('error', 'Tidak ada shift kasir yang terbuka.');
        }

        $validated = $request->validate([
            'type' => 'required|in:in,out',
            'category' => 'required|string|max:50',
            'amount' => 'required|numeric|min:1',
            'party_name' => 'nullable|string|max:255',
            'notes' => 'required|string|max:1000',
        ]);

        $allowedCategories = array_keys(CashTransaction::categoriesForType($validated['type']));
        if (! in_array($validated['category'], $allowedCategories, true)) {
            return back()->withErrors(['category' => 'Kategori tidak valid untuk jenis transaksi ini.']);
        }

        DB::transaction(function () use ($store, $user, $openShift, $validated) {
            $ct = CashTransaction::create([
                'store_id' => $store->id,
                'user_id' => $user->id,
                'cash_shift_id' => $openShift->id,
                'type' => $validated['type'],
                'category' => $validated['category'],
                'amount' => $validated['amount'],
                'party_name' => $validated['party_name'] ?? null,
                'notes' => $validated['notes'],
            ]);

            JournalPoster::postCashTransaction($store, $ct);
        });

        $msg = $validated['type'] === 'in' ? 'Uang masuk berhasil dicatat.' : 'Uang keluar berhasil dicatat.';

        return back()->with('success', $msg);
    }

    private function resolveOpenShift(Request $request, $store, User $user): ?CashShift
    {
        $isPinMode = ($store->cashier_login_mode ?? 'email') === 'pin';
        $activeCashier = $request->session()->get('active_cashier');

        if ($isPinMode && $activeCashier) {
            $openShift = CashShift::query()
                ->where('store_id', $store->id)
                ->whereNull('closed_at')
                ->where('employee_id', $activeCashier['employee_id'])
                ->first();

            if ($openShift) {
                return $openShift;
            }
        }

        return CashShift::query()
            ->where('store_id', $store->id)
            ->whereNull('closed_at')
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildReportEntries(
        int $storeId,
        string $startDate,
        string $endDate,
        ?string $type,
        ?string $category,
        ?int $userId,
    ): Collection {
        $entries = collect();

        $includePos = (! $type || $type === CashTransaction::TYPE_IN)
            && (! $category || $category === 'pos_cash');

        if ($includePos) {
            // Daftar penerimaan tunai dari POS: transaksi yang punya porsi tunai,
            // termasuk transaksi split tender yang sebagian dibayar non-tunai.
            $posQuery = Transaction::query()
                ->where('store_id', $storeId)
                ->where('cash_amount', '>', 0)
                ->where('status', 'completed')
                ->whereDate('created_at', '>=', $startDate)
                ->whereDate('created_at', '<=', $endDate)
                ->with(['user:id,name', 'customer:id,name', 'cashShift:id,shift_number']);

            if ($userId) {
                $posQuery->where('user_id', $userId);
            }

            foreach ($posQuery->get() as $sale) {
                $entries->push($this->serializePosCashSale($sale));
            }
        }

        $includeManual = ! $category || $category !== 'pos_cash';

        if ($includeManual) {
            $manualQuery = CashTransaction::query()
                ->where('store_id', $storeId)
                ->whereDate('created_at', '>=', $startDate)
                ->whereDate('created_at', '<=', $endDate)
                ->with(['user:id,name', 'cashShift:id,shift_number']);

            if (in_array($type, [CashTransaction::TYPE_IN, CashTransaction::TYPE_OUT], true)) {
                $manualQuery->where('type', $type);
            }

            if ($category && $category !== 'pos_cash') {
                $manualQuery->where('category', $category);
            }

            if ($userId) {
                $manualQuery->where('user_id', $userId);
            }

            foreach ($manualQuery->get() as $transaction) {
                $entries->push(array_merge($this->serializeTransaction($transaction), [
                    'source' => 'manual',
                    'id' => 'manual-'.$transaction->id,
                ]));
            }
        }

        return $entries
            ->sortByDesc(fn (array $row) => $row['created_at'])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePosCashSale(Transaction $sale): array
    {
        return [
            'id' => 'pos-'.$sale->id,
            'source' => 'pos',
            'type' => CashTransaction::TYPE_IN,
            'type_label' => 'Uang Masuk',
            'category' => 'pos_cash',
            'category_label' => 'Penjualan Tunai (POS)',
            // Porsi tunainya saja: transaksi split tender hanya menyetor sebagian ke laci.
            'amount' => (string) $sale->cash_amount,
            'party_name' => $sale->customer?->name,
            'notes' => 'Penjualan #'.($sale->transaction_number ?? $sale->id),
            'recorded_by' => $sale->user?->name,
            'shift_number' => $sale->cashShift?->shift_number,
            'created_at' => ($sale->completed_at ?? $sale->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     opening_cash: float,
     *     sales_cash: float,
     *     cash_in: float,
     *     cash_out: float,
     *     expected_cash: float
     * }
     */
    private function buildShiftSummary(CashShift $openShift): array
    {
        $salesCash = (float) Transaction::query()
            ->where('cash_shift_id', $openShift->id)
            ->where('status', 'completed')
            ->sum('cash_amount');

        $cashIn = (float) CashTransaction::query()
            ->where('cash_shift_id', $openShift->id)
            ->where('type', CashTransaction::TYPE_IN)
            ->sum('amount');

        $cashOut = (float) CashTransaction::query()
            ->where('cash_shift_id', $openShift->id)
            ->where('type', CashTransaction::TYPE_OUT)
            ->sum('amount');

        $openingCash = (float) $openShift->opening_cash;
        $expectedCash = $openingCash + $salesCash + $cashIn - $cashOut;

        return [
            'opening_cash' => $openingCash,
            'sales_cash' => $salesCash,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected_cash' => $expectedCash,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CashTransaction>  $transactions
     * @return array<int, array<string, mixed>>
     */
    private function serializeTransactions($transactions): array
    {
        return $transactions
            ->map(fn (CashTransaction $transaction) => $this->serializeTransaction($transaction))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTransaction(CashTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'type_label' => CashTransaction::typeLabel($transaction->type),
            'category' => $transaction->category,
            'category_label' => CashTransaction::categoryLabel($transaction->category),
            'amount' => (string) $transaction->amount,
            'party_name' => $transaction->party_name,
            'notes' => $transaction->notes,
            'recorded_by' => $transaction->user?->name,
            'shift_number' => $transaction->cashShift?->shift_number,
            'created_at' => $transaction->created_at?->toIso8601String(),
        ];
    }
}
