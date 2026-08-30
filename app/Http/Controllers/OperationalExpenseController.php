<?php

namespace App\Http\Controllers;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperationalExpense;
use App\Models\Store;
use App\Support\Accounting\AccountingFeatures;
use App\Support\Accounting\AccountingPeriodGuard;
use App\Support\Accounting\ChartOfAccountsBootstrap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OperationalExpenseController extends Controller
{
    public function index(Request $request)
    {
        $store = $this->resolveStore($request);

        $query = OperationalExpense::where('store_id', $store->id)
            ->with('user:id,name')
            ->latest('expense_date');

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('start_date')) {
            $query->where('expense_date', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->where('expense_date', '<=', $request->input('end_date'));
        }

        $expenses = $query->paginate(50)->withQueryString();

        $summary = OperationalExpense::where('store_id', $store->id)
            ->selectRaw('SUM(amount) as total, COUNT(*) as count')
            ->first();

        $byCategory = OperationalExpense::where('store_id', $store->id)
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'categories' => OperationalExpense::CATEGORIES,
            'summary' => [
                'total' => (float) $summary->total,
                'count' => (int) $summary->count,
            ],
            'byCategory' => $byCategory,
            'filters' => $request->only(['category', 'start_date', 'end_date']),
        ]);
    }

    public function store(Request $request)
    {
        $store = $this->resolveStore($request);

        $validated = $request->validate([
            'category' => 'required|string|max:50',
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01|max:99999999999.99',
            'expense_date' => 'required|date',
            'payment_method' => 'nullable|string|in:cash,transfer,ewallet',
            'notes' => 'nullable|string|max:1000',
        ]);

        $validated['store_id'] = $store->id;
        $validated['user_id'] = auth()->id();
        $validated['payment_method'] = $validated['payment_method'] ?? 'cash';
        AccountingPeriodGuard::assertDateOpen($store, $validated['expense_date']);

        return DB::transaction(function () use ($store, $validated) {
            // 1. Simpan beban
            $expense = OperationalExpense::create($validated);

            // 2. Posting jurnal otomatis (double-entry, hanya jika modul akuntansi aktif)
            $kas = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_KAS);
            $bank = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BANK);
            $beban = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BEBAN_OPERASIONAL);

            if (AccountingFeatures::enabled($store) && $kas && $bank && $beban) {
                $payingAccount = $validated['payment_method'] === 'cash' ? $kas : $bank;
                $payingLabel = $validated['payment_method'] === 'cash' ? 'Kas' : 'Bank';

                $entry = JournalEntry::create([
                    'store_id' => $store->id,
                    'entry_date' => $validated['expense_date'],
                    'reference_type' => OperationalExpense::class,
                    'reference_id' => $expense->id,
                    'description' => 'Beban operasional: '.$validated['description'],
                    'user_id' => $validated['user_id'],
                ]);

                // Debit: Beban Operasional
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'chart_of_account_id' => $beban->id,
                    'debit' => $validated['amount'],
                    'credit' => 0,
                    'memo' => $validated['category'].': '.$validated['description'],
                ]);

                // Kredit: Kas/Bank
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'chart_of_account_id' => $payingAccount->id,
                    'debit' => 0,
                    'credit' => $validated['amount'],
                    'memo' => 'Pembayaran beban via '.$payingLabel,
                ]);

                $expense->update(['journal_entry_id' => $entry->id]);
            }

            return back()->with('success', 'Biaya operasional berhasil dicatat.');
        });
    }

    public function destroy(OperationalExpense $expense)
    {
        $store = $this->resolveStore(request());

        if ($expense->store_id !== $store->id) {
            abort(403);
        }

        return DB::transaction(function () use ($store, $expense) {
            // Balikkan jurnal jika ada
            if ($expense->journal_entry_id) {
                $entry = $expense->journalEntry;
                if ($entry) {
                    AccountingPeriodGuard::assertDateOpen($store, $entry->entry_date);
                    JournalLine::where('journal_entry_id', $entry->id)->delete();
                    $entry->delete();
                }
            }

            $expense->delete();

            return back()->with('success', 'Biaya operasional berhasil dihapus.');
        });
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
