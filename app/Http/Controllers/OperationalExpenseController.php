<?php

namespace App\Http\Controllers;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperationalExpense;
use App\Models\Store;
use App\Support\Accounting\AccountingFeatures;
use App\Support\Accounting\AccountingPeriodGuard;
use App\Support\Accounting\ChartOfAccountsBootstrap;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OperationalExpenseController extends Controller
{
    /** Metode bayar yang bisa dipilih (seragam dengan kasir). "transfer" tetap diterima untuk data lama. */
    private const PAYMENT_METHODS = ['cash', 'ewallet', 'other', 'transfer'];

    public function index(Request $request)
    {
        $store = $this->resolveStore($request);
        $tz = $store->timezone ?: config('app.timezone');
        $today = CarbonImmutable::now($tz);

        // Default: bulan berjalan (waktu toko), bukan semua waktu.
        $start = $this->parseDate($request->input('start_date')) ?? $today->startOfMonth();
        $end = $this->parseDate($request->input('end_date')) ?? $today;
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        $category = $request->filled('category') ? (string) $request->input('category') : null;
        $search = trim((string) $request->input('q', ''));

        $base = fn (CarbonImmutable $from, CarbonImmutable $to): Builder => OperationalExpense::query()
            ->where('store_id', $store->id)
            ->whereDate('expense_date', '>=', $from->toDateString())
            ->whereDate('expense_date', '<=', $to->toDateString())
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('description', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")));

        $expenses = $base($start, $end)
            ->with('user:id,name')
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (OperationalExpense $e) => [
                'id' => $e->id,
                'category' => $e->category,
                'description' => $e->description,
                'amount' => (float) $e->amount,
                'expense_date' => $e->expense_date?->toDateString(),
                'payment_method' => $e->payment_method,
                'notes' => $e->notes,
                'user' => $e->user ? ['id' => $e->user->id, 'name' => $e->user->name] : null,
            ]);

        $total = (float) $base($start, $end)->sum('amount');
        $count = $base($start, $end)->count();

        // Pembanding: periode sama panjang tepat sebelumnya. Untuk rentang yang mulai tanggal 1
        // (mis. "bulan ini" s.d. hari ini) dibandingkan dengan tanggal yang sama di bulan lalu.
        $days = (int) $start->diffInDays($end) + 1;
        if ($start->day === 1 && $start->isSameMonth($end)) {
            $prevStart = $start->subMonthNoOverflow();
            $prevEnd = $prevStart->addDays($days - 1)->min($prevStart->endOfMonth()->startOfDay());
        } else {
            $prevEnd = $start->subDay();
            $prevStart = $prevEnd->subDays($days - 1);
        }
        $prevTotal = (float) $base($prevStart, $prevEnd)->sum('amount');

        // Rincian kategori tidak ikut filter kategori, supaya tetap bisa dipakai memilih kategori.
        $byCategory = OperationalExpense::query()
            ->where('store_id', $store->id)
            ->whereDate('expense_date', '>=', $start->toDateString())
            ->whereDate('expense_date', '<=', $end->toDateString())
            ->when($search !== '', fn ($q) => $q->where('description', 'like', "%{$search}%"))
            ->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['category' => $r->category, 'total' => (float) $r->total, 'count' => (int) $r->count]);

        // Subtotal harian untuk tanggal yang tampil di halaman ini.
        $dates = collect($expenses->items())->pluck('expense_date')->unique()->values();
        $dailyTotals = $dates->isEmpty() ? [] : $base($start, $end)
            ->whereIn(DB::raw('DATE(expense_date)'), $dates->all())
            ->selectRaw('DATE(expense_date) as d, SUM(amount) as total')
            ->groupBy('d')
            ->pluck('total', 'd')
            ->map(fn ($v) => (float) $v);

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'categories' => OperationalExpense::CATEGORIES,
            'summary' => [
                'total' => $total,
                'count' => $count,
                'previous_total' => $prevTotal,
                'previous_start' => $prevStart->toDateString(),
                'previous_end' => $prevEnd->toDateString(),
                'days' => $days,
            ],
            'byCategory' => $byCategory,
            'dailyTotals' => $dailyTotals,
            'filters' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'category' => $category,
                'q' => $search,
            ],
            'today' => $today->toDateString(),
        ]);
    }

    public function store(Request $request)
    {
        $store = $this->resolveStore($request);
        $validated = $this->validated($request);
        $validated['store_id'] = $store->id;
        $validated['user_id'] = auth()->id();
        AccountingPeriodGuard::assertDateOpen($store, $validated['expense_date']);

        DB::transaction(function () use ($store, $validated) {
            $expense = OperationalExpense::create($validated);
            $this->postJournal($store, $expense);
        });

        return back()->with('success', 'Biaya usaha dicatat.');
    }

    public function update(Request $request, OperationalExpense $expense)
    {
        $store = $this->resolveStore($request);
        abort_if((int) $expense->store_id !== (int) $store->id, 403);

        $validated = $this->validated($request);
        AccountingPeriodGuard::assertDateOpen($store, $expense->expense_date->toDateString());
        AccountingPeriodGuard::assertDateOpen($store, $validated['expense_date']);

        DB::transaction(function () use ($store, $expense, $validated) {
            $this->removeJournal($expense);
            $expense->update($validated + ['journal_entry_id' => null]);
            $this->postJournal($store, $expense->fresh());
        });

        return back()->with('success', 'Biaya usaha diperbarui.');
    }

    public function destroy(OperationalExpense $expense)
    {
        $store = $this->resolveStore(request());
        abort_if((int) $expense->store_id !== (int) $store->id, 403);

        DB::transaction(function () use ($store, $expense) {
            if ($expense->journalEntry) {
                AccountingPeriodGuard::assertDateOpen($store, $expense->journalEntry->entry_date);
            }
            $this->removeJournal($expense);
            $expense->delete();
        });

        return back()->with('success', 'Biaya usaha dihapus.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'category' => 'required|string|max:50',
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01|max:99999999999.99',
            'expense_date' => 'required|date',
            'payment_method' => 'nullable|string|in:'.implode(',', self::PAYMENT_METHODS),
            'notes' => 'nullable|string|max:1000',
        ], [
            'category.required' => 'Pilih kategori biaya.',
            'description.required' => 'Isi keterangan biaya.',
            'amount.required' => 'Isi jumlah biaya.',
            'amount.min' => 'Jumlah biaya harus lebih dari 0.',
        ]);
        $validated['payment_method'] = $validated['payment_method'] ?? 'cash';

        return $validated;
    }

    /** Jurnal otomatis (hanya jika modul akuntansi aktif): Debit Beban, Kredit Kas (tunai) atau Bank (lainnya). */
    private function postJournal(Store $store, OperationalExpense $expense): void
    {
        $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
        $bank = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BANK);
        $beban = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BEBAN_OPERASIONAL);

        if (! AccountingFeatures::enabled($store) || ! $kas || ! $bank || ! $beban) {
            return;
        }

        $isCash = $expense->payment_method === 'cash';
        $amount = (string) $expense->amount;

        $entry = JournalEntry::create([
            'store_id' => $store->id,
            'entry_date' => $expense->expense_date->toDateString(),
            'reference_type' => OperationalExpense::class,
            'reference_id' => $expense->id,
            'description' => 'Beban operasional: '.$expense->description,
            'user_id' => $expense->user_id,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $beban->id,
            'debit' => $amount,
            'credit' => 0,
            'memo' => $expense->category.': '.$expense->description,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => ($isCash ? $kas : $bank)->id,
            'debit' => 0,
            'credit' => $amount,
            'memo' => 'Pembayaran beban via '.($isCash ? 'Kas' : 'Bank'),
        ]);

        $expense->update(['journal_entry_id' => $entry->id]);
    }

    private function removeJournal(OperationalExpense $expense): void
    {
        $entry = $expense->journalEntry;
        if ($entry) {
            JournalLine::where('journal_entry_id', $entry->id)->delete();
            $entry->delete();
        }
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveStore(Request $request): Store
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            abort(403, 'Tidak ada toko aktif.');
        }

        if ($user->isOwner()) {
            $storeId = $request->integer('store_id');
            if ($storeId) {
                $owned = $user->stores()->pluck('stores.id')->toArray();
                if (in_array($storeId, $owned, true)) {
                    return Store::findOrFail($storeId);
                }
            }
        }

        return $store;
    }
}
