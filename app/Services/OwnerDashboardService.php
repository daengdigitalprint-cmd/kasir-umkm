<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class OwnerDashboardService
{
    private const CACHE_TTL = 600; // 10 menit

    private const PERIOD_FORMATS = [
        'daily' => 'Y-m-d',
        'weekly' => 'Y-W',
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

            // Data periode aktif
            $currentData = $this->queryRevenue($storeIds, $start, $end, $dateFormat);
            // Data periode sebelumnya (untuk hitung pertumbuhan)
            $previousData = $this->queryRevenue($storeIds, $prevStart, $prevEnd, $dateFormat);

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

                $summary[$sid] = [
                    'name' => $storeName,
                    'color' => $color,
                    'total' => $currentTotal,
                    'average' => $currentTotal / $periodCount,
                    'growth' => $growth,
                    'previous_total' => $previousTotal,
                ];
            }

            // Label berdasarkan periode
            $labels = array_map(function ($p) use ($period) {
                $carbon = $this->periodToCarbon($period, $p);
                $format = self::PERIOD_LABELS[$period] ?? 'd M Y';

                return $carbon->format($format);
            }, $allPeriods);

            return [
                'labels' => $labels,
                'period_keys' => $allPeriods,
                'datasets' => $datasets,
                'summary' => $summary,
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
        $data = $this->queryRevenue($storeIds, $start, $end, $dateFormat);

        $rows = [];
        foreach ($data as $periodKey => $storeRevenues) {
            foreach ($storeRevenues as $sid => $revenue) {
                $rows[] = [
                    'Periode' => $periodKey,
                    'Outlet' => $stores[$sid]->name ?? "Toko #{$sid}",
                    'Pendapatan' => $revenue,
                ];
            }
        }

        usort($rows, fn ($a, $b) => strcmp($a['Periode'], $b['Periode']) ?: strcmp($a['Outlet'], $b['Outlet']));

        return $rows;
    }

    /**
     * @param  array<int>  $storeIds
     * @return array<string, array<int, float>>
     */
    private function queryRevenue(array $storeIds, Carbon $start, Carbon $end, string $dateFormat): array
    {
        // Grup berdasarkan DATE(created_at) di SQL, lalu format di PHP
        // untuk menghindari perbedaan DATE_FORMAT antara MySQL dan SQLite.
        $rows = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'completed')
            ->selectRaw('store_id, DATE(created_at) as day_key, SUM(total_amount) as revenue')
            ->groupBy('store_id', 'day_key')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $periodKey = Carbon::parse($row->day_key)->format($dateFormat);
            $result[$periodKey][$row->store_id] = (float) $row->revenue;
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
        // weekly: "2026-W28" format dari strftime
        if (preg_match('/(\d{4})-W(\d{2})/', $key, $m)) {
            return Carbon::parse("{$m[1]}-01-01")->addWeeks((int) $m[2] - 1)->startOfWeek();
        }

        return Carbon::parse($key)->startOfWeek();
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
