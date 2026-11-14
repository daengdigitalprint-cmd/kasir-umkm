<?php

namespace App\Tools\Reports;

use App\Models\TransactionItem;
use App\Models\User;
use App\Services\AI\AiPeriod;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Carbon;

class SalesForecastTool extends AiTool
{
    protected const DAY_NAMES = [
        'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu',
    ];

    public function getName(): string
    {
        return 'forecast_sales';
    }

    public function getDescription(): string
    {
        return 'Memprediksi penjualan N hari ke depan (forecast/demand planning) berdasarkan pola penjualan historis: rata-rata berbobot terakhir + pola hari dalam seminggu (weekend/mingguan). Gunakan saat user bertanya "prediksi penjualan", "forecast penjualan", "perkiraan omzet minggu depan", "akan laku berapa", atau proyeksi penjualan masa depan.';
    }

    public function getParameters(): array
    {
        return [
            'forecast_days' => [
                'type' => 'integer',
                'description' => 'Berapa hari ke depan yang diprediksi (default: 7, maksimal: 90)',
            ],
            'history_days' => [
                'type' => 'integer',
                'description' => 'Berapa hari histori penjualan yang dipakai sebagai dasar prediksi (default: 90, maksimal: 365)',
            ],
            'product_name' => [
                'type' => 'string',
                'description' => 'Prediksi untuk produk spesifik saja (opsional, dicari dengan LIKE)',
            ],
            'scope' => [
                'type' => 'string',
                'description' => 'Cakupan data: "current" hanya outlet aktif user (default), "all" menggabungkan SEMUA outlet/cabang dalam grup usaha yang sama. Hanya berlaku untuk owner/admin/manager.',
                'enum' => ['current', 'all'],
            ],
        ];
    }

    public function execute(array $args, User $user): ToolResult
    {
        $store = $user->store;
        if (! $store) {
            return ToolResult::error('User tidak memiliki toko');
        }

        $forecastDays = max(1, min(90, (int) ($args['forecast_days'] ?? 7)));
        $historyDays = max(7, min(365, (int) ($args['history_days'] ?? 90)));
        $scope = AiStoreScope::resolve($user, $args['scope'] ?? AiStoreScope::CURRENT);

        // Histori berakhir KEMARIN (jam toko). Hari ini baru berjalan sebagian;
        // bila ikut dihitung — dengan bobot terbesar — prediksi jadi terlalu rendah.
        $today = AiPeriod::now($store)->startOfDay();
        $historyTo = $today->copy()->subDay();
        $historyFrom = $today->copy()->subDays($historyDays);

        $query = TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->whereIn('transactions.store_id', $scope['store_ids'])
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [AiPeriod::toDb($historyFrom), AiPeriod::toDb($historyTo->copy()->endOfDay())])
            ->where('transactions.is_void', false)
            ->whereNotNull('transaction_items.product_id');

        if (! empty($args['product_name'])) {
            $query->where('transaction_items.product_name', 'like', "%{$args['product_name']}%");
        }

        // Dikelompokkan per hari menurut jam toko (bukan jam server).
        $dailyRows = [];
        foreach ($query->get(['transactions.created_at as sold_at', 'transaction_items.quantity', 'transaction_items.total_price']) as $row) {
            $day = AiPeriod::toStore($row->sold_at, $store)->format('Y-m-d');
            $dailyRows[$day] ??= ['qty' => 0.0, 'revenue' => 0.0];
            $dailyRows[$day]['qty'] += (float) $row->quantity;
            $dailyRows[$day]['revenue'] += (float) $row->total_price;
        }

        // Toko/produk yang baru berjualan: mulai dari hari penjualan pertama,
        // supaya hari-hari sebelum buka tidak dihitung sebagai "hari sepi".
        if ($dailyRows !== []) {
            $firstSale = Carbon::parse(min(array_keys($dailyRows)), $today->getTimezone())->startOfDay();
            if ($firstSale->gt($historyFrom)) {
                $historyFrom = $firstSale;
            }
        }

        $effectiveDays = (int) $historyFrom->diffInDays($historyTo) + 1;

        $historyDates = collect(range(0, max(0, $effectiveDays - 1)))
            ->map(fn (int $offset) => $historyFrom->copy()->addDays($offset))
            ->values();

        $qtySeries = $historyDates->map(fn (Carbon $date) => (float) ($dailyRows[$date->format('Y-m-d')]['qty'] ?? 0))->all();
        $revenueSeries = $historyDates->map(fn (Carbon $date) => (float) ($dailyRows[$date->format('Y-m-d')]['revenue'] ?? 0))->all();

        $totalQty = array_sum($qtySeries);
        $daysWithSales = count(array_filter($qtySeries, fn (float $qty) => $qty > 0));
        $confidence = $this->confidence($effectiveDays, $daysWithSales);

        $baseQty = $this->recentWeightedAverage($qtySeries);
        $baseRevenue = $this->recentWeightedAverage($revenueSeries);
        $qtyFactors = $this->weekdayFactors($historyDates, $qtySeries);
        $revenueFactors = $this->weekdayFactors($historyDates, $revenueSeries);

        $forecastDates = collect(range(1, $forecastDays))
            ->map(fn (int $offset) => $today->copy()->addDays($offset))
            ->values();

        $items = $forecastDates->map(function (Carbon $date) use ($baseQty, $baseRevenue, $qtyFactors, $revenueFactors) {
            $dayIndex = (int) $date->dayOfWeekIso - 1;
            $forecastQty = round(max(0, $baseQty * $qtyFactors[$dayIndex]), 1);
            $forecastRevenue = round(max(0, $baseRevenue * $revenueFactors[$dayIndex]));

            return [
                'date' => $date->format('d/m/Y'),
                'day' => self::DAY_NAMES[$dayIndex],
                'forecast_qty' => $forecastQty,
                'forecast_revenue' => $forecastRevenue,
            ];
        })->values();

        $totalForecastQty = round($items->sum('forecast_qty'), 1);
        $totalForecastRevenue = (int) $items->sum('forecast_revenue');
        $avgPerDayQty = round($totalForecastQty / $forecastDays, 1);

        $bestDay = $items->sortByDesc('forecast_qty')->first();
        $avgRevenuePerUnit = $totalQty > 0 ? (int) round(array_sum($revenueSeries) / $totalQty) : 0;

        $data = [
            'forecast_days' => $forecastDays,
            'history_days' => $historyDays,
            'scope' => $scope['all_branches'] ? AiStoreScope::ALL : AiStoreScope::CURRENT,
            'product_name' => $args['product_name'] ?? null,
            'history_total_qty' => $totalQty,
            'avg_sales_per_day' => round($totalQty / max(1, $effectiveDays), 2),
            'history_days_used' => $effectiveDays,
            'confidence' => $confidence,
            'method' => 'rata-rata berbobot 14 hari terakhir + faktor hari dalam seminggu',
            'items' => $items,
            'total_forecast_qty' => $totalForecastQty,
            'total_forecast_revenue' => $totalForecastRevenue,
            'best_day' => $bestDay['day'] ?? null,
        ];

        if ($totalForecastQty <= 0 && $totalForecastRevenue <= 0) {
            return ToolResult::success(
                data: $data,
                message: "Belum ada data penjualan dalam {$historyDays} hari terakhir, sehingga prediksi untuk {$forecastDays} hari ke depan masih nol. Setelah ada transaksi, prediksi akan otomatis mengikuti pola penjualan."
            );
        }

        $productLabel = ! empty($args['product_name']) ? " produk **{$args['product_name']}**" : '';
        $message = "Perkiraan penjualan{$productLabel} {$forecastDays} hari ke depan: "
            .'total **'.rtrim(rtrim((string) $totalForecastQty, '0'), '.').' unit** '
            ."(± {$this->formatCurrency((float) $totalForecastRevenue)}), rata-rata {$avgPerDayQty} unit/hari.";

        if ($bestDay && $bestDay['forecast_qty'] > 0) {
            $message .= " Hari paling laris diprediksi: **{$bestDay['day']}** ({$bestDay['forecast_qty']} unit).";
        }

        if ($avgRevenuePerUnit > 0) {
            $message .= " Nilai historis per unit ± {$this->formatCurrency((float) $avgRevenuePerUnit)}.";
        }

        $message .= " Keyakinan prediksi: {$confidence} (basis {$effectiveDays} hari histori sampai kemarin, {$daysWithSales} hari ada penjualan)."
            .($confidence === 'rendah' ? ' Histori masih pendek/jarang — anggap angka ini perkiraan kasar, bukan target.' : '').'';

        if ($scope['all_branches']) {
            $message = '**Semua cabang** ('.count($scope['store_ids'])." outlet):\n\n".$message;
        }

        return ToolResult::success(data: $data, message: $message);
    }

    /**
     * Rata-rata berbobot 14 hari terakhir (hari terbaru paling berbobot) agar
     * prediksi mengikuti tren terkini, bukan rata-rata lama yang sudah basi.
     *
     * @param  array<int, float>  $series
     */
    protected function recentWeightedAverage(array $series): float
    {
        $recent = array_slice($series, -14);
        if ($recent === []) {
            return 0.0;
        }

        $totalWeight = 0.0;
        $weightedSum = 0.0;
        $count = count($recent);

        foreach ($recent as $index => $value) {
            $weight = $index + 1;
            $weightedSum += $value * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? $weightedSum / $totalWeight : 0.0;
    }

    /**
     * Faktor koreksi per hari dalam seminggu (Senin..Minggu): rata-rata penjualan
     * hari itu dibanding rata-rata harian. Dibatasi 0.5–1.5 agar hari sepi/tanpa
     * data historis tidak membuat prediksi ekstrem.
     *
     * @param  \Illuminate\Support\Collection<int, Carbon>  $historyDates
     * @param  array<int, float>  $series
     * @return array<int, float>
     */
    protected function weekdayFactors($historyDates, array $series): array
    {
        $sums = array_fill(0, 7, 0.0);
        $counts = array_fill(0, 7, 0);

        foreach ($historyDates as $index => $date) {
            $dayIndex = (int) $date->dayOfWeekIso - 1;
            $sums[$dayIndex] += $series[$index];
            $counts[$dayIndex]++;
        }

        $overall = array_sum($series) / max(1, count($series));

        if ($overall <= 0) {
            return array_fill(0, 7, 1.0);
        }

        return array_map(function (float $sum, int $count) use ($overall) {
            if ($count < 2) {
                return 1.0;
            }

            return min(1.5, max(0.5, ($sum / $count) / $overall));
        }, $sums, $counts);
    }

    protected function confidence(int $historyDays, int $daysWithSales): string
    {
        if ($historyDays >= 56 && $daysWithSales >= 28) {
            return 'tinggi';
        }

        if ($historyDays >= 28 && $daysWithSales >= 14) {
            return 'sedang';
        }

        return 'rendah';
    }
}
