<?php

namespace App\Support\Accounting;

use App\Models\AccountingPeriod;
use App\Models\CashShift;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingPeriodCloser
{
    /**
     * Kumpulkan checklist pra-penutupan periode.
     * Dipakai frontend untuk menampilkan modal konfirmasi berisi ringkasan
     * kondisi data sebelum user menutup buku.
     *
     * @return array{
     *     can_close: bool,
     *     blocking: array<int, array{label: string, detail: string}>,
     *     warnings: array<int, array{label: string, detail: string}>
     * }
     */
    public static function preCloseChecklist(AccountingPeriod $period): array
    {
        $blocking = [];
        $warnings = [];

        $store = $period->store;

        // 1. Shift kasir yang masih terbuka dalam rentang periode.
        $openShifts = CashShift::query()
            ->where('store_id', $store->id)
            ->whereNull('closed_at')
            ->where('opened_at', '<=', $period->end_date->endOfDay())
            ->count();

        if ($openShifts > 0) {
            $blocking[] = [
                'label' => 'Shift kasir belum ditutup',
                'detail' => "Masih ada {$openShifts} shift kasir yang berstatus terbuka pada rentang periode ini. Tutup semua shift sebelum menutup buku agar selisih kas tercatat dengan benar.",
            ];
        }

        // 2. Transaksi POS dalam periode yang terkait shift yang belum ditutup.
        $unbalancedShifts = CashShift::query()
            ->where('store_id', $store->id)
            ->whereNotNull('closed_at')
            ->whereBetween('opened_at', [$period->start_date->startOfDay(), $period->end_date->endOfDay()])
            ->where(function ($query) {
                $query->whereNull('closing_cash_counted')
                    ->orWhereNull('expected_cash')
                    ->orWhereNull('difference_amount');
            })
            ->count();

        if ($unbalancedShifts > 0) {
            $warnings[] = [
                'label' => 'Data penghitungan kas belum lengkap',
                'detail' => "Ada {$unbalancedShifts} shift yang ditutup tanpa penghitungan kas lengkap. Pertimbangkan untuk mengecek ulang laporan shift sebelum menutup buku.",
            ];
        }

        return [
            'can_close' => count($blocking) === 0,
            'blocking' => $blocking,
            'warnings' => $warnings,
        ];
    }

    public static function close(AccountingPeriod $period, User $user): void
    {
        $period->refresh();
        if ($period->status !== AccountingPeriod::STATUS_OPEN) {
            throw ValidationException::withMessages(['period' => 'Periode sudah ditutup.']);
        }

        DB::transaction(function () use ($period, $user) {
            $period->refresh();
            if ($period->status !== AccountingPeriod::STATUS_OPEN) {
                throw ValidationException::withMessages(['period' => 'Periode sudah ditutup.']);
            }

            $store = $period->store;
            ChartOfAccountsBootstrap::ensureForStore($store);
            $saldoLaba = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_SALDO_LABA);

            $accounts = ChartOfAccount::query()
                ->where('store_id', $store->id)
                ->whereIn('type', [ChartOfAccount::TYPE_REVENUE, ChartOfAccount::TYPE_COGS, ChartOfAccount::TYPE_EXPENSE])
                ->orderBy('sort_order')
                ->get();

            $balances = JournalLine::query()
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_entries.store_id', $store->id)
                ->whereBetween('journal_entries.entry_date', [$period->start_date, $period->end_date])
                ->whereIn('journal_lines.chart_of_account_id', $accounts->pluck('id'))
                ->groupBy('journal_lines.chart_of_account_id')
                ->selectRaw('journal_lines.chart_of_account_id, COALESCE(SUM(debit), 0) AS debit, COALESCE(SUM(credit), 0) AS credit')
                ->get()
                ->keyBy('chart_of_account_id');

            $entry = JournalEntry::query()->create([
                'store_id' => $store->id,
                'entry_date' => $period->end_date->toDateString(),
                'reference_type' => AccountingPeriod::class,
                'reference_id' => $period->id,
                'description' => 'Jurnal penutup '.$period->name,
                'user_id' => $user->id,
            ]);

            $totalDebit = 0.0;
            $totalCredit = 0.0;
            foreach ($accounts as $account) {
                $balance = $balances->get($account->id);
                $net = round((float) ($balance?->debit ?? 0) - (float) ($balance?->credit ?? 0), 2);
                if (abs($net) < 0.01) {
                    continue;
                }

                $debit = $net < 0 ? abs($net) : 0;
                $credit = $net > 0 ? $net : 0;
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'chart_of_account_id' => $account->id,
                    'debit' => $debit,
                    'credit' => $credit,
                    'memo' => 'Penutupan '.$account->name,
                ]);
                $totalDebit += $debit;
                $totalCredit += $credit;
            }

            $difference = round($totalDebit - $totalCredit, 2);
            if (abs($difference) >= 0.01) {
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'chart_of_account_id' => $saldoLaba->id,
                    'debit' => $difference < 0 ? abs($difference) : 0,
                    'credit' => $difference > 0 ? $difference : 0,
                    'memo' => 'Pemindahan laba/rugi ke saldo laba',
                ]);
            }

            $period->update([
                'status' => AccountingPeriod::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $user->id,
                'closing_journal_entry_id' => $entry->id,
            ]);
        });
    }

    public static function reopen(AccountingPeriod $period, User $user, string $reason): void
    {
        if ($period->status !== AccountingPeriod::STATUS_CLOSED) {
            throw ValidationException::withMessages(['period' => 'Periode tidak sedang ditutup, tidak dapat dibuka ulang.']);
        }

        DB::transaction(function () use ($period, $user, $reason) {
            $period->refresh();

            if ($period->status !== AccountingPeriod::STATUS_CLOSED) {
                throw ValidationException::withMessages(['period' => 'Periode tidak sedang ditutup, tidak dapat dibuka ulang.']);
            }

            $closingEntryId = $period->closing_journal_entry_id;

            $period->update([
                'status' => AccountingPeriod::STATUS_OPEN,
                'closed_at' => null,
                'closing_journal_entry_id' => null,
                'reopened_at' => now(),
                'reopened_by' => $user->id,
                'reopen_reason' => $reason,
            ]);

            // Hapus jurnal penutup beserta seluruh barisnya agar laporan laba rugi
            // periode kembali bersih dan penutupan berikutnya tidak menghasilkan duplikasi.
            JournalLine::query()
                ->where('journal_entry_id', $closingEntryId)
                ->delete();

            JournalEntry::query()
                ->whereKey($closingEntryId)
                ->delete();
        });
    }
}
