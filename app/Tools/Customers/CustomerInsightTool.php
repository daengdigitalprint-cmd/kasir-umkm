<?php

namespace App\Tools\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Carbon;

class CustomerInsightTool extends AiTool
{
    public function getName(): string
    {
        return 'get_customer_insight';
    }

    public function getDescription(): string
    {
        return 'Menganalisis pelanggan: pelanggan paling berharga (top spender), pelanggan yang mulai jarang belanja (risiko churn), dan saldo poin loyalty. Gunakan saat user bertanya "siapa pelanggan terbaik saya", "pelanggan mana yang mulai jarang", "poin loyalty pelanggan", atau analisis pelanggan lainnya.';
    }

    public function getParameters(): array
    {
        return [
            'inactive_days' => [
                'type' => 'integer',
                'description' => 'Batas hari tanpa belanja untuk dianggap "mulai jarang" (default: 30)',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Jumlah pelanggan per daftar (default: 5)',
            ],
        ];
    }

    public function execute(array $args, User $user): ToolResult
    {
        $store = $user->store;
        if (! $store) {
            return ToolResult::error('User tidak memiliki toko');
        }

        $inactiveDays = max(1, (int) ($args['inactive_days'] ?? 30));
        $limit = max(1, min(20, (int) ($args['limit'] ?? 5)));

        $totalCustomers = Customer::query()
            ->where('store_id', $store->id)
            ->active()
            ->count();

        if ($totalCustomers === 0) {
            return ToolResult::success(
                data: ['total_customers' => 0],
                message: 'Belum ada data pelanggan di toko ini.'
            );
        }

        $topCustomers = Customer::query()
            ->where('store_id', $store->id)
            ->active()
            ->where('total_spent', '>', 0)
            ->orderByDesc('total_spent')
            ->limit($limit)
            ->get()
            ->map(fn (Customer $c) => $this->customerRow($c));

        $inactiveCutoff = Carbon::now()->subDays($inactiveDays);
        $atRiskCustomers = Customer::query()
            ->where('store_id', $store->id)
            ->active()
            ->where('total_orders', '>=', 1)
            ->where(function ($query) use ($inactiveCutoff) {
                $query->whereNull('last_purchase_at')
                    ->orWhere('last_purchase_at', '<', $inactiveCutoff);
            })
            ->orderBy('last_purchase_at')
            ->limit($limit)
            ->get()
            ->map(function (Customer $c) {
                $row = $this->customerRow($c);
                $row['days_inactive'] = $c->last_purchase_at
                    ? (int) $c->last_purchase_at->diffInDays(Carbon::now())
                    : null;

                return $row;
            });

        $loyaltySummary = Customer::query()
            ->where('store_id', $store->id)
            ->active()
            ->selectRaw('
                COALESCE(SUM(points_balance), 0) as points_in_circulation,
                COUNT(CASE WHEN points_balance > 0 THEN 1 END) as customers_with_points
            ')
            ->first();

        $topPoints = Customer::query()
            ->where('store_id', $store->id)
            ->active()
            ->where('points_balance', '>', 0)
            ->orderByDesc('points_balance')
            ->limit($limit)
            ->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'points_balance' => (int) $c->points_balance,
                'points_earned_total' => (int) $c->points_earned_total,
                'points_redeemed_total' => (int) $c->points_redeemed_total,
            ]);

        $data = [
            'total_customers' => $totalCustomers,
            'top_customers' => $topCustomers->values()->all(),
            'at_risk_customers' => $atRiskCustomers->values()->all(),
            'inactive_days_threshold' => $inactiveDays,
            'loyalty' => [
                'points_in_circulation' => (int) ($loyaltySummary->points_in_circulation ?? 0),
                'customers_with_points' => (int) ($loyaltySummary->customers_with_points ?? 0),
                'top_by_points' => $topPoints->values()->all(),
            ],
        ];

        return ToolResult::success(
            data: $data,
            message: $this->summaryMessage($data)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function customerRow(Customer $c): array
    {
        $totalSpent = (float) $c->total_spent;
        $totalOrders = (int) $c->total_orders;

        return [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone ?? '-',
            'total_spent' => (int) round($totalSpent),
            'total_spent_formatted' => $this->formatCurrency($totalSpent),
            'total_orders' => $totalOrders,
            'average_order_value' => $totalOrders > 0 ? (int) round($totalSpent / $totalOrders) : 0,
            'last_purchase_at' => $c->last_purchase_at?->format('d/m/Y'),
            'points_balance' => (int) $c->points_balance,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function summaryMessage(array $data): string
    {
        $top = $data['top_customers'][0] ?? null;
        $atRisk = $data['at_risk_customers'][0] ?? null;

        $message = "Total pelanggan aktif: {$data['total_customers']}.";

        if ($top) {
            $message .= " Pelanggan teratas: {$top['name']} dengan total belanja {$top['total_spent_formatted']} dari {$top['total_orders']} transaksi.";
        }

        $atRiskCount = count($data['at_risk_customers']);
        if ($atRiskCount > 0) {
            $threshold = $data['inactive_days_threshold'];
            $message .= " {$atRiskCount} pelanggan mulai jarang belanja (≥{$threshold} hari tidak belanja)";
            if ($atRisk) {
                $inactiveLabel = $atRisk['days_inactive'] !== null
                    ? "{$atRisk['days_inactive']} hari"
                    : 'belum pernah belanja';
                $message .= ", terlama: {$atRisk['name']} ({$inactiveLabel})";
            }
            $message .= '.';
        }

        $loyalty = $data['loyalty'];
        $message .= " Poin loyalty beredar: {$loyalty['points_in_circulation']} poin dari {$loyalty['customers_with_points']} pelanggan.";

        return $message;
    }
}
