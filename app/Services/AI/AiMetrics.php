<?php

namespace App\Services\AI;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Definisi metrik keuangan untuk tool AI — disamakan dengan laporan
 * (KPIService / OwnerDashboardService) supaya angka asisten tidak berbeda
 * dari angka di dasbor.
 *
 * - Pendapatan bersih = subtotal − diskon − retur. BUKAN total_amount, karena
 *   total_amount memuat pajak, service charge, dan tip yang bukan pendapatan usaha.
 * - HPP hanya dihitung dari baris yang harga modalnya benar-benar tercatat.
 *   Baris tanpa harga modal TIDAK dianggap bermodal nol; porsinya dilaporkan
 *   lewat `cost_coverage_pct` agar margin tidak disimpulkan dari data bolong.
 */
class AiMetrics
{
    /** Pendapatan bersih per transaksi (sebelum retur). */
    public const NET_SALES_SQL = 'COALESCE(SUM(transactions.subtotal - transactions.discount_amount), 0)';

    /**
     * HPP satu baris item, atau NULL bila harga modalnya tidak tercatat.
     * Urutan sumber: harga modal satuan → total modal → turunan dari snapshot laba.
     */
    public const LINE_COST_SQL = '(CASE
        WHEN COALESCE(transaction_items.unit_cost_snapshot, 0) > 0
            THEN transaction_items.quantity * transaction_items.unit_cost_snapshot
        WHEN COALESCE(transaction_items.total_cost_snapshot, 0) > 0
            THEN transaction_items.total_cost_snapshot
        WHEN transaction_items.gross_profit_snapshot IS NOT NULL
            AND transaction_items.gross_profit_snapshot <> 0
            AND transaction_items.total_price - transaction_items.gross_profit_snapshot > 0
            THEN transaction_items.total_price - transaction_items.gross_profit_snapshot
        ELSE NULL
    END)';

    /** Di bawah ambang ini margin tidak layak dijadikan dasar rekomendasi. */
    public const RELIABLE_COVERAGE_PCT = 80.0;

    /**
     * Ringkasan penjualan & laba kotor untuk sekumpulan outlet.
     *
     * @param  array<int, int>  $storeIds
     * @return array{
     *     revenue: float, gross_sales: float, returns: float, collected: float, transactions: int,
     *     items_sold: float, average_transaction: float, hpp: float, gross_profit: float,
     *     gross_margin_pct: float|null, cost_coverage_pct: float, covered_margin_pct: float|null,
     *     margin_reliable: bool
     * }
     */
    public static function summary(array $storeIds, Carbon $from, Carbon $to): array
    {
        $sales = DB::table('transactions')
            ->whereIn('store_id', $storeIds)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(self::NET_SALES_SQL.' as net_sales, COALESCE(SUM(total_amount), 0) as collected, COUNT(*) as transactions')
            ->first();

        $returns = (float) DB::table('sales_returns')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('returned_at', [$from, $to])
            ->sum(DB::raw('subtotal - discount_amount'));

        $lines = self::itemsQuery($storeIds, $from, $to)
            ->selectRaw('
                COALESCE(SUM(transaction_items.quantity), 0) as items_sold,
                COALESCE(SUM(transaction_items.total_price), 0) as line_revenue,
                COALESCE(SUM('.self::LINE_COST_SQL.'), 0) as hpp,
                COALESCE(SUM(CASE WHEN '.self::LINE_COST_SQL.' IS NOT NULL THEN transaction_items.total_price ELSE 0 END), 0) as covered_revenue
            ')
            ->first();

        $grossSales = (float) ($sales->net_sales ?? 0);
        $revenue = $grossSales - $returns;
        $transactions = (int) ($sales->transactions ?? 0);
        $hpp = (float) ($lines->hpp ?? 0);
        $lineRevenue = (float) ($lines->line_revenue ?? 0);
        $coveredRevenue = (float) ($lines->covered_revenue ?? 0);
        $coverage = $lineRevenue > 0 ? round($coveredRevenue / $lineRevenue * 100, 1) : 0.0;
        $grossProfit = $revenue - $hpp;

        return [
            'revenue' => round($revenue),
            'gross_sales' => round($grossSales),
            'returns' => round($returns),
            'collected' => round((float) ($sales->collected ?? 0)),
            'transactions' => $transactions,
            'items_sold' => (float) ($lines->items_sold ?? 0),
            'average_transaction' => $transactions > 0 ? round($revenue / $transactions) : 0.0,
            'hpp' => round($hpp),
            'gross_profit' => round($grossProfit),
            'gross_margin_pct' => $revenue > 0 ? round($grossProfit / $revenue * 100, 1) : null,
            'cost_coverage_pct' => $coverage,
            // Margin hanya pada bagian penjualan yang harga modalnya tercatat.
            'covered_margin_pct' => $coveredRevenue > 0 ? round(($coveredRevenue - $hpp) / $coveredRevenue * 100, 1) : null,
            'margin_reliable' => $lineRevenue > 0 && $coverage >= self::RELIABLE_COVERAGE_PCT,
        ];
    }

    /**
     * Penjualan, HPP, dan margin per produk.
     *
     * @param  array<int, int>  $storeIds
     * @return \Illuminate\Support\Collection<int, array{product_id: int, product_name: string, quantity: float, revenue: float, discount: float, hpp: float|null, gross_profit: float|null, margin_pct: float|null, has_cost: bool, transactions: int}>
     */
    public static function byProduct(array $storeIds, Carbon $from, Carbon $to): \Illuminate\Support\Collection
    {
        return self::itemsQuery($storeIds, $from, $to)
            ->whereNotNull('transaction_items.product_id')
            ->groupBy('transaction_items.product_id')
            ->selectRaw('
                transaction_items.product_id as product_id,
                MAX(transaction_items.product_name) as product_name,
                COALESCE(SUM(transaction_items.quantity), 0) as quantity,
                COALESCE(SUM(transaction_items.total_price), 0) as revenue,
                COALESCE(SUM(transaction_items.discount_amount), 0) as discount,
                COALESCE(SUM('.self::LINE_COST_SQL.'), 0) as hpp,
                COALESCE(SUM(CASE WHEN '.self::LINE_COST_SQL.' IS NOT NULL THEN transaction_items.total_price ELSE 0 END), 0) as covered_revenue,
                COUNT(DISTINCT transaction_items.transaction_id) as transactions
            ')
            ->get()
            ->map(function ($row) {
                $revenue = (float) $row->revenue;
                $covered = (float) $row->covered_revenue;
                // Margin produk hanya dipercaya bila (hampir) semua penjualannya punya harga modal.
                $hasCost = $revenue > 0 && $covered >= $revenue * 0.95;
                $hpp = (float) $row->hpp;

                return [
                    'product_id' => (int) $row->product_id,
                    'product_name' => (string) ($row->product_name ?? 'Tanpa nama'),
                    'quantity' => round((float) $row->quantity, 2),
                    'revenue' => round($revenue),
                    'discount' => round((float) $row->discount),
                    'hpp' => $hasCost ? round($hpp) : null,
                    'gross_profit' => $hasCost ? round($revenue - $hpp) : null,
                    'margin_pct' => $hasCost ? round(($revenue - $hpp) / $revenue * 100, 1) : null,
                    'has_cost' => $hasCost,
                    'transactions' => (int) $row->transactions,
                ];
            })
            ->values();
    }

    /**
     * @param  array<int, int>  $storeIds
     */
    public static function itemsQuery(array $storeIds, Carbon $from, Carbon $to): \Illuminate\Database\Query\Builder
    {
        return DB::table('transaction_items')
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->whereIn('transactions.store_id', $storeIds)
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$from, $to]);
    }

    /** Kalimat peringatan bila harga modal belum lengkap; null bila aman. */
    public static function coverageNote(array $summary): ?string
    {
        if ($summary['revenue'] <= 0 || $summary['cost_coverage_pct'] >= 99.5) {
            return null;
        }

        $coverage = self::number($summary['cost_coverage_pct']);

        if ($summary['cost_coverage_pct'] <= 0) {
            return 'Harga modal (HPP) belum tercatat sama sekali pada penjualan periode ini, jadi laba kotor dan margin BELUM bisa dihitung. Isi harga modal produk supaya analisa laba akurat.';
        }

        $covered = $summary['covered_margin_pct'] !== null
            ? ' Pada bagian yang tercatat, margin kotornya '.self::number($summary['covered_margin_pct']).'%.'
            : '';

        return "Catatan: harga modal baru tercatat untuk {$coverage}% penjualan, sehingga laba kotor di atas cenderung terlalu tinggi.{$covered} Lengkapi harga modal produk agar angkanya akurat.";
    }

    /** Angka gaya Indonesia tanpa nol desimal berlebih (12,5 / 1.250). */
    public static function number(float|int|string|null $value, int $decimals = 1): string
    {
        $formatted = number_format((float) $value, $decimals, ',', '.');

        return str_contains($formatted, ',') ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
    }

    public static function rupiah(float|int|string|null $value): string
    {
        return 'Rp '.number_format((float) $value, 0, ',', '.');
    }
}
