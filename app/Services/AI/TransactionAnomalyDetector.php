<?php

namespace App\Services\AI;

use App\Models\CashShift;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class TransactionAnomalyDetector
{
    public const DEFAULT_DISCOUNT_THRESHOLD_PCT = 30.0;

    public const DEFAULT_VOID_THRESHOLD = 3;

    /**
     * Transaksi dengan nilai diskon di atas persentase subtotal tertentu.
     *
     * @param  array<int, int>  $storeIds
     * @return array<int, array<string, mixed>>
     */
    public function findDiscountAnomalies(array $storeIds, Carbon $from, Carbon $to, float $thresholdPct = self::DEFAULT_DISCOUNT_THRESHOLD_PCT): array
    {
        $discounts = [];

        $transactions = Transaction::query()
            ->whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$from, $to])
            ->where('is_void', false)
            ->where('discount_amount', '>', 0)
            ->with('user:id,name')
            ->get();

        foreach ($transactions as $transaction) {
            $subtotal = (float) $transaction->subtotal;
            $discount = (float) $transaction->discount_amount;
            if ($subtotal <= 0) {
                continue;
            }
            $pct = ($discount / $subtotal) * 100;
            if ($pct < $thresholdPct) {
                continue;
            }

            $discounts[] = [
                'transaction_id' => $transaction->id,
                'transaction_number' => $transaction->transaction_number,
                'cashier' => $transaction->user?->name ?? 'Tidak diketahui',
                'discount_amount' => $discount,
                'discount_pct' => round($pct, 1),
                'total_amount' => (float) $transaction->total_amount,
                'created_at' => $this->formatDate($transaction->created_at),
            ];
        }

        return $discounts;
    }

    /**
     * Kasir yang membatalkan transaksi (void) berulang pada periode.
     *
     * @param  array<int, int>  $storeIds
     * @return array<int, array<string, mixed>>
     */
    public function findRepeatedVoids(array $storeIds, Carbon $from, Carbon $to, int $threshold = self::DEFAULT_VOID_THRESHOLD): array
    {
        $rows = Transaction::query()
            ->whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$from, $to])
            ->where('is_void', true)
            ->selectRaw('COALESCE(void_by, user_id) as cashier_id, COUNT(*) as void_count, SUM(total_amount) as total_void_amount')
            ->groupBy('cashier_id')
            ->having('void_count', '>=', $threshold)
            ->with('user:id,name')
            ->get();

        $patterns = [];
        foreach ($rows as $row) {
            $cashier = User::find($row->cashier_id);
            $sample = Transaction::query()
                ->whereIn('store_id', $storeIds)
                ->whereBetween('created_at', [$from, $to])
                ->where('is_void', true)
                ->where(fn ($q) => $q->where('void_by', $row->cashier_id)->orWhere('user_id', $row->cashier_id))
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id', 'transaction_number', 'total_amount', 'void_reason', 'void_at'])
                ->map(fn ($t) => [
                    'transaction_number' => $t->transaction_number,
                    'amount' => (float) $t->total_amount,
                    'void_reason' => $t->void_reason,
                    'void_at' => $this->formatDate($t->void_at ?? $t->created_at),
                ]);

            $patterns[] = [
                'cashier_id' => $row->cashier_id,
                'cashier' => $cashier?->name ?? 'Tidak diketahui',
                'void_count' => (int) $row->void_count,
                'total_void_amount' => (float) $row->total_void_amount,
                'sample_transactions' => $sample->values()->all(),
            ];
        }

        return $patterns;
    }

    /**
     * Shift kas fisik yang ditutup dengan selisih (kurang/lebih uang).
     *
     * @param  array<int, int>  $storeIds
     * @return array<int, array<string, mixed>>
     */
    public function findCashDifferences(array $storeIds, Carbon $from, Carbon $to): array
    {
        return CashShift::query()
            ->whereIn('store_id', $storeIds)
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$from, $to])
            ->where('difference_amount', '!=', 0)
            ->with('user:id,name')
            ->orderByRaw('ABS(difference_amount) DESC')
            ->get()
            ->map(fn (CashShift $shift) => [
                'shift_id' => $shift->id,
                'cashier' => $shift->user?->name ?? 'Tidak diketahui',
                'expected_cash' => (float) $shift->expected_cash,
                'counted_cash' => (float) $shift->closing_cash_counted,
                'difference' => (float) $shift->difference_amount,
                'closed_at' => $this->formatDate($shift->closed_at),
            ])
            ->all();
    }

    private function formatDate($date): string
    {
        if (is_string($date)) {
            $date = Carbon::parse($date);
        }

        return $date->format('d/m/Y H:i');
    }

    private function formatCurrency(float $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
