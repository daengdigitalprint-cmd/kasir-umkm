<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OwnerDashboardService
{
    private const CACHE_TTL = 600; // 10 menit

    private const PERIOD_FORMATS = [
        'daily' => 'Y-m-d',
        // 'o' = tahun ISO (wajib dipasangkan dengan pekan ISO 'W'; memakai 'Y'
        // membuat pekan lintas tahun masuk ember yang salah). '\W' = huruf W
        // literal, pembeda dari kunci bulanan "2026-11".
        'weekly' => 'o-\WW',
        'monthly' => 'Y-m',
        'yearly' => 'Y',
    ];

    private const PERIOD_LABELS = [
        'daily' => 'd M',
        'weekly' => 'M W',
        'monthly' => 'M Y',
        'yearly' => 'Y',
    ];

    /**
     * Mendapatkan semua outlet milik owner.
     */
    public function ownerStores(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->stores()->select('stores.id', 'stores.name', 'stores.slug')->orderBy('name')->get();
    }

    /**
     * Mendapatkan data pendapatan untuk grafik.
     *
     * @return array{labels: list<string>, datasets: list<array>, summary: array<string, mixed>}
     */
    public function revenueData(User $user, string $period, string $startDate, string $endDate, ?int $storeId = null): array
    {
        $storeIds = $this->resolveStoreIds($user, $storeId);
        $cacheKey = "owner_rev_{$user->id}_{$period}_{$startDate}_{$endDate}_".md5(implode(',', $storeIds));

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user, $storeIds, $period, $startDate, $endDate) {
            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->endOfDay();
            $prevEnd = $start->copy()->subDay();
            $prevStart = $this->previousPeriodStart($period, $start);

            $stores = $this->ownerStores($user)->keyBy('id');
            $dateFormat = self::PERIOD_FORMATS[$period] ?? 'Y-m-d';

            // Penjualan bersih dikurangi retur, untuk periode aktif & sebelumnya.
            $currentData = $this->netOfReturns(
                $this->queryRevenue($storeIds, $start, $end, $dateFormat),
                $this->queryReturns($storeIds, $start, $end, $dateFormat),
            );
            $previousData = $this->netOfReturns(
                $this->queryRevenue($storeIds, $prevStart, $prevEnd, $dateFormat),
                $this->queryReturns($storeIds, $prevStart, $prevEnd, $dateFormat),
            );

            // HPP per outlet untuk menghitung laba kotor.
            $cogsByStore = $this->queryCogs($storeIds, $start, $end);

            // Build labels (semua periode unik dari kedua dataset)
            $allPeriods = collect(array_keys($currentData + $previousData))->sort()->values()->all();

            // Build per-outlet series
            $datasets = [];
            $summary = [];

            foreach ($storeIds as $sid) {
                $storeName = $stores[$sid]->name ?? "Toko #{$sid}";
                $color = $this->outletColor($sid, $storeIds);

                $series = [];
                foreach ($allPeriods as $period_key) {
                    $series[] = (float) ($currentData[$period_key][$sid] ?? 0);
                }

                $datasets[] = [
                    'label' => $storeName,
                    'data' => $series,
                    'borderColor' => $color,
                    'backgroundColor' => $color.'33',
                    'store_id' => $sid,
                ];

                // Ringkasan per outlet
                $currentTotal = 0;
                $previousTotal = 0;
                foreach ($currentData as $periodData) {
                    $currentTotal += $periodData[$sid] ?? 0;
                }
                foreach ($previousData as $periodData) {
                    $previousTotal += $periodData[$sid] ?? 0;
                }
                $periodCount = max(count($allPeriods), 1);
                $growth = $previousTotal > 0 ? (($currentTotal - $previousTotal) / $previousTotal) * 100 : null;

                // Laba kotor = penjualan bersih - HPP.
                $cogsRow = $cogsByStore[$sid] ?? ['cogs' => 0.0, 'covered' => 0.0, 'total' => 0.0];
                $cogs = $cogsRow['cogs'];
                $grossProfit = $currentTotal - $cogs;

                // Berapa bagian penjualan yang harga modalnya diketahui.
                // Tanpa ini, outlet tanpa data HPP akan tampak bermargin 100%.
                $coverage = $cogsRow['total'] > 0
                    ? ($cogsRow['covered'] / $cogsRow['total']) * 100
                    : 0.0;

                $summary[$sid] = [
                    'name' => $storeName,
                    'color' => $color,
                    'total' => $currentTotal,
                    'average' => $currentTotal / $periodCount,
                    'growth' => $growth,
                    'previous_total' => $previousTotal,
                    'cogs' => $cogs,
                    'gross_profit' => $grossProfit,
                    'margin' => $currentTotal > 0 ? ($grossProfit / $currentTotal) * 100 : null,
                    'cogs_coverage' => round($coverage, 1),
                ];
            }

            // Label berdasarkan periode
            $labels = array_map(function ($p) use ($period) {
                $carbon = $this->periodToCarbon($period, $p);
                $format = self::PERIOD_LABELS[$period] ?? 'd M Y';

                return $carbon->format($format);
            }, $allPeriods);

            // Ringkasan gabungan seluruh outlet terpilih.
            $totalNet = array_sum(array_column($summary, 'total'));
            $totalCogs = array_sum(array_column($summary, 'cogs'));
            $coveredRevenue = array_sum(array_column($cogsByStore, 'covered'));
            $itemRevenue = array_sum(array_column($cogsByStore, 'total'));

            $totals = [
                'net_revenue' => $totalNet,
                'cogs' => $totalCogs,
                'gross_profit' => $totalNet - $totalCogs,
                'margin' => $totalNet > 0 ? (($totalNet - $totalCogs) / $totalNet) * 100 : null,
                // Pajak, service charge, dan tip ikut tertagih di kasir tapi
                // bukan pendapatan usaha — ditampilkan agar selisih dengan
                // uang yang masuk laci bisa dijelaskan.
                'collected_not_revenue' => $this->queryTax($storeIds, $start, $end),
                'returns' => array_sum(array_map(
                    'array_sum',
                    $this->queryReturns($storeIds, $start, $end, $dateFormat)
                )),
                'cogs_coverage' => $itemRevenue > 0 ? round(($coveredRevenue / $itemRevenue) * 100, 1) : 0.0,
            ];

            return [
                'labels' => $labels,
                'period_keys' => $allPeriods,
                'datasets' => $datasets,
                'summary' => $summary,
                'totals' => $totals,
                'period' => $period,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ];
        });
    }

    /**
     * Mendapatkan data detail transaksi per outlet untuk export.
     *
     * @return list<array>
     */
    public function exportData(User $user, string $period, string $startDate, string $endDate): array
    {
        $storeIds = $this->resolveStoreIds($user);
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();
        $dateFormat = self::PERIOD_FORMATS[$period] ?? 'Y-m-d';

        $stores = $this->ownerStores($user)->keyBy('id');

        // Angka yang sama dengan dashboard: penjualan bersih setelah retur,
        // tanpa pajak/service charge/tip.
        $data = $this->netOfReturns(
            $this->queryRevenue($storeIds, $start, $end, $dateFormat),
            $this->queryReturns($storeIds, $start, $end, $dateFormat),
        );

        $rows = [];
        foreach ($data as $periodKey => $storeRevenues) {
            foreach ($storeRevenues as $sid => $revenue) {
                $rows[] = [
                    'Periode' => $periodKey,
                    'Outlet' => $stores[$sid]->name ?? "Toko #{$sid}",
                    'Penjualan Bersih' => $revenue,
                ];
            }
        }

        usort($rows, fn ($a, $b) => strcmp($a['Periode'], $b['Periode']) ?: strcmp($a['Outlet'], $b['Outlet']));

        return $rows;
    }

    /**
     * Penjualan bersih per periode per outlet.
     *
     * Memakai (subtotal - diskon), BUKAN total_amount. total_amount dibentuk
     * sebagai subtotal - diskon + pajak + service charge + tip, sehingga
     * memuat uang yang bukan pendapatan usaha: PPN milik negara dan tip milik
     * karyawan. Retur penjualan dikurangkan terpisah lewat queryReturns().
     *
     * @param  array<int>  $storeIds
     * @return array<string, array<int, float>>
     */
    private function queryRevenue(array $storeIds, Carbon $start, Carbon $end, string $dateFormat): array
    {
        $rows = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'completed')
            ->selectRaw('store_id, DATE(created_at) as day_key, SUM(subtotal - discount_amount) as revenue')
            ->groupBy('store_id', 'day_key')
            ->get();

        return $this->bucketByPeriod($rows, $dateFormat, 'revenue');
    }

    /**
     * Pajak yang ikut tertagih (bukan pendapatan usaha). Ditampilkan terpisah
     * supaya pemilik paham selisihnya dengan uang yang masuk laci.
     *
     * @param  array<int>  $storeIds
     */
    private function queryTax(array $storeIds, Carbon $start, Carbon $end): float
    {
        return (float) Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'completed')
            ->sum(DB::raw('tax_amount + service_charge_amount + tip_amount'));
    }

    /**
     * Retur penjualan per periode per outlet, memakai nilai sebelum pajak
     * agar sebanding dengan penjualan bersih.
     *
     * @param  array<int>  $storeIds
     * @return array<string, array<int, float>>
     */
    private function queryReturns(array $storeIds, Carbon $start, Carbon $end, string $dateFormat): array
    {
        $rows = DB::table('sales_returns')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('returned_at', [$start, $end])
            ->selectRaw('store_id, DATE(returned_at) as day_key, SUM(subtotal - discount_amount) as revenue')
            ->groupBy('store_id', 'day_key')
            ->get();

        return $this->bucketByPeriod($rows, $dateFormat, 'revenue');
    }

    /**
     * HPP dan cakupannya per outlet.
     *
     * Tidak semua baris transaksi punya harga modal (produk tanpa harga beli,
     * atau dicatat sebelum fitur ini ada). Cakupan dihitung berbobot omzet —
     * bukan jumlah baris — supaya menggambarkan seberapa besar bagian
     * penjualan yang marginnya benar-benar terhitung.
     *
     * @param  array<int>  $storeIds
     * @return array<int, array{cogs: float, covered: float, total: float}>
     */
    private function queryCogs(array $storeIds, Carbon $start, Carbon $end): array
    {
        $rows = DB::table('transaction_items as ti')
            ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
            ->whereIn('t.store_id', $storeIds)
            ->whereBetween('t.created_at', [$start, $end])
            ->where('t.status', 'completed')
            ->selectRaw('
                t.store_id,
                SUM(ti.quantity * COALESCE(ti.unit_cost_snapshot, 0)) as cogs,
                SUM(CASE WHEN COALESCE(ti.unit_cost_snapshot, 0) > 0 THEN ti.total_price ELSE 0 END) as covered,
                SUM(ti.total_price) as total
            ')
            ->groupBy('t.store_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->store_id] = [
                'cogs' => (float) $row->cogs,
                'covered' => (float) $row->covered,
                'total' => (float) $row->total,
            ];
        }

        return $result;
    }

    /**
     * Kurangkan retur dari penjualan, per periode per outlet.
     *
     * @param  array<string, array<int, float>>  $revenue
     * @param  array<string, array<int, float>>  $returns
     * @return array<string, array<int, float>>
     */
    private function netOfReturns(array $revenue, array $returns): array
    {
        foreach ($returns as $periodKey => $byStore) {
            foreach ($byStore as $storeId => $amount) {
                $revenue[$periodKey][$storeId] = ($revenue[$periodKey][$storeId] ?? 0.0) - $amount;
            }
        }

        return $revenue;
    }

    /**
     * Kelompokkan baris harian ke kunci periode.
     *
     * SQL mengelompokkan per hari, sedangkan satu kunci periode
     * (pekan/bulan/tahun) menampung banyak hari. Harus dijumlahkan:
     * penugasan biasa membuat hari terakhir menimpa hari sebelumnya,
     * sehingga pendapatan sepekan/sebulan hanya terbaca satu hari.
     *
     * @param  iterable<object>  $rows
     * @return array<string, array<int, float>>
     */
    private function bucketByPeriod(iterable $rows, string $dateFormat, string $valueKey): array
    {
        $result = [];

        foreach ($rows as $row) {
            // Format di PHP, bukan DATE_FORMAT di SQL, supaya hasilnya sama
            // antara MySQL dan SQLite.
            $periodKey = Carbon::parse($row->day_key)->format($dateFormat);
            $storeId = (int) $row->store_id;

            $result[$periodKey][$storeId] =
                ($result[$periodKey][$storeId] ?? 0.0) + (float) $row->{$valueKey};
        }

        return $result;
    }

    /**
     * @param  array<int>  $storeIds
     */
    private function resolveStoreIds(User $user, ?int $specificStoreId = null): array
    {
        $all = $user->stores()->pluck('stores.id')->toArray();

        if ($specificStoreId !== null && in_array($specificStoreId, $all, true)) {
            return [$specificStoreId];
        }

        return $all;
    }

    private function previousPeriodStart(string $period, Carbon $start): Carbon
    {
        return match ($period) {
            'daily' => $start->copy()->subDays(30),
            'weekly' => $start->copy()->subWeeks(12),
            'monthly' => $start->copy()->subMonths(12),
            'yearly' => $start->copy()->subYears(5),
            default => $start->copy()->subDays(30),
        };
    }

    private function periodToCarbon(string $period, string $key): Carbon
    {
        if ($period === 'daily') {
            return Carbon::parse($key);
        }
        if ($period === 'yearly') {
            return Carbon::parse($key.'-01-01');
        }
        if ($period === 'monthly') {
            return Carbon::parse($key.'-01');
        }
        // weekly: kunci "2026-W42" (tahun ISO + nomor pekan ISO).
        if (preg_match('/^(\d{4})-W(\d{1,2})$/', $key, $m)) {
            // setISODate menempatkan tanggal pada pekan ISO yang diminta.
            // Menambah pekan dari 1 Januari meleset saat 1 Januari masih
            // termasuk pekan terakhir tahun sebelumnya.
            return Carbon::now()->setISODate((int) $m[1], (int) $m[2])->startOfWeek();
        }

        // Kunci di luar dugaan tidak boleh menjatuhkan dashboard dengan 500.
        try {
            return Carbon::parse($key)->startOfWeek();
        } catch (\Throwable $e) {
            Log::warning('OwnerDashboard: kunci periode tidak dikenali', [
                'period' => $period,
                'key' => $key,
            ]);

            return Carbon::now()->startOfWeek();
        }
    }

    /**
     * Warna konsisten per outlet berdasarkan posisi.
     */
    private function outletColor(int $storeId, array $allStoreIds): string
    {
        $palette = [
            '#0891B2', // cyan
            '#F59E0B', // amber
            '#10B981', // emerald
            '#8B5CF6', // violet
            '#EF4444', // red
            '#3B82F6', // blue
            '#F97316', // orange
            '#EC4899', // pink
            '#14B8A6', // teal
            '#6366F1', // indigo
        ];

        $index = array_search($storeId, $allStoreIds, true);

        return $palette[($index !== false ? $index : 0) % count($palette)];
    }
}
