<?php

namespace App\Tools\Reports;

use App\Models\Transaction;
use App\Models\User;
use App\Services\AI\AiStoreScope;
use App\Support\BarbershopFeatures;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Carbon;

class BarberCommissionTool extends AiTool
{
    public function getName(): string
    {
        return 'get_barber_commissions';
    }

    public function getDescription(): string
    {
        return 'Mendapatkan rekap komisi barber/teknisi cukur per periode: total komisi, jumlah transaksi, omzet barber, dan rincian per barber. Gunakan saat user bertanya "komisi barber", "cek komisi barber/teknisi", "komisi cukur". Hanya relevan untuk toko barbershop.';
    }

    public function getParameters(): array
    {
        return [
            'period' => [
                'type' => 'string',
                'description' => 'Periode laporan: "today", "yesterday", "this_week", "last_week", "this_month", "last_month", "this_year"',
                'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year'],
            ],
            'date_from' => [
                'type' => 'string',
                'description' => 'Tanggal mulai (format: Y-m-d), opsional jika period diisi',
            ],
            'date_to' => [
                'type' => 'string',
                'description' => 'Tanggal akhir (format: Y-m-d), opsional jika period diisi',
            ],
            'barber_name' => [
                'type' => 'string',
                'description' => 'Filter nama barber tertentu (opsional, pencocokan parsial)',
            ],
            'scope' => [
                'type' => 'string',
                'description' => 'Cakupan data: "current" hanya outlet aktif (default), "all" gabung semua outlet grup usaha',
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

        if (! BarbershopFeatures::enabled($store)) {
            return ToolResult::error('Fitur komisi barber hanya tersedia untuk toko dengan tipe usaha barbershop.');
        }

        $scope = AiStoreScope::resolve($user, $args['scope'] ?? AiStoreScope::CURRENT);
        $storeIds = $scope['store_ids'];
        $allBranches = $scope['all_branches'];

        $dateRange = $this->getDateRange($args);
        $from = $dateRange['from'];
        $to = $dateRange['to'];

        $barberFilterId = null;

        if (! empty($args['barber_name'])) {
            $needle = trim((string) $args['barber_name']);
            if ($needle !== '') {
                // Cari user barber di toko/organisasi yang namanya mirip
                $candidate = User::query()
                    ->whereHas('stores', function ($q) use ($storeIds) {
                        $q->whereIn('stores.id', $storeIds);
                    })
                    ->where('name', 'like', '%'.$needle.'%')
                    ->first();

                if ($candidate) {
                    $barberFilterId = $candidate->id;
                } else {
                    // Tetap lanjut tanpa filter tapi informasikan tidak ditemukan
                    return ToolResult::success(
                        data: [
                            'scope' => $allBranches ? AiStoreScope::ALL : AiStoreScope::CURRENT,
                            'stores_analyzed' => count($storeIds),
                            'period' => ['from' => $from->format('d/m/Y'), 'to' => $to->format('d/m/Y')],
                            'total_commission' => $this->formatCurrency(0),
                            'total_commission_raw' => 0,
                            'transaction_count' => 0,
                            'gross_revenue' => $this->formatCurrency(0),
                            'barber_count' => 0,
                            'rows' => [],
                        ],
                        message: 'Tidak ditemukan barber dengan nama "'.$needle.'" pada periode '.$from->format('d/m/Y').' - '.$to->format('d/m/Y').'.'
                    );
                }
            }
        }

        $query = Transaction::whereIn('store_id', $storeIds)
            ->where('status', 'completed')
            ->whereNotNull('barber_user_id')
            ->whereBetween('created_at', [$from, $to]);

        if ($barberFilterId !== null) {
            $query->where('barber_user_id', $barberFilterId);
        }

        $rows = $query
            ->selectRaw('barber_user_id, COUNT(*) as tx_count, COALESCE(SUM(total_amount),0) as gross, COALESCE(SUM(barber_commission_amount),0) as commission')
            ->groupBy('barber_user_id')
            ->with('barber')
            ->get();

        $mapped = $rows->map(fn ($row) => [
            'barber_id' => $row->barber_user_id,
            'barber_name' => $row->barber?->name ?? 'Barber (nonaktif)',
            'transaction_count' => (int) $row->tx_count,
            'gross_revenue' => round((float) $row->gross, 2),
            'gross_revenue_formatted' => $this->formatCurrency((float) $row->gross),
            'commission_amount' => round((float) $row->commission, 2),
            'commission_formatted' => $this->formatCurrency((float) $row->commission),
        ])->sortByDesc('commission_amount')->values();

        $totalCommission = (float) $mapped->sum('commission_amount');
        $totalGross = (float) $mapped->sum('gross_revenue');
        $totalTx = (int) $mapped->sum('transaction_count');

        $data = [
            'scope' => $allBranches ? AiStoreScope::ALL : AiStoreScope::CURRENT,
            'stores_analyzed' => count($storeIds),
            'period' => ['from' => $from->format('d/m/Y'), 'to' => $to->format('d/m/Y')],
            'total_commission' => $this->formatCurrency($totalCommission),
            'total_commission_raw' => round($totalCommission, 2),
            'transaction_count' => $totalTx,
            'gross_revenue' => $this->formatCurrency($totalGross),
            'gross_revenue_raw' => round($totalGross, 2),
            'barber_count' => $mapped->count(),
            'rows' => $mapped->all(),
        ];

        if ($mapped->isEmpty()) {
            return ToolResult::success(
                data: $data,
                message: 'Belum ada transaksi dengan barber pada periode '.$from->format('d/m/Y').' - '.$to->format('d/m/Y').'. Pastikan barber dipilih saat pembayaran di POS.'
            );
        }

        $message = $this->summaryMessage($data, $allBranches, count($storeIds));

        return ToolResult::success(data: $data, message: $message);
    }

    protected function getDateRange(array $args): array
    {
        if (! empty($args['date_from']) && ! empty($args['date_to'])) {
            return [
                'from' => Carbon::parse($args['date_from'])->startOfDay(),
                'to' => Carbon::parse($args['date_to'])->endOfDay(),
            ];
        }

        $period = $args['period'] ?? 'this_month';
        $now = Carbon::now();

        return match ($period) {
            'today' => ['from' => $now->copy()->startOfDay(), 'to' => $now->copy()->endOfDay()],
            'yesterday' => ['from' => $now->copy()->subDay()->startOfDay(), 'to' => $now->copy()->subDay()->endOfDay()],
            'this_week' => ['from' => $now->copy()->startOfWeek(), 'to' => $now->copy()->endOfWeek()],
            'last_week' => ['from' => $now->copy()->subWeek()->startOfWeek(), 'to' => $now->copy()->subWeek()->endOfWeek()],
            'this_month' => ['from' => $now->copy()->startOfMonth(), 'to' => $now->copy()->endOfMonth()],
            'last_month' => ['from' => $now->copy()->subMonth()->startOfMonth(), 'to' => $now->copy()->subMonth()->endOfMonth()],
            'this_year' => ['from' => $now->copy()->startOfYear(), 'to' => $now->copy()->endOfYear()],
            default => ['from' => $now->copy()->startOfMonth(), 'to' => $now->copy()->endOfMonth()],
        };
    }

    protected function summaryMessage(array $data, bool $allBranches, int $storeCount): string
    {
        $period = $data['period']['from'].' - '.$data['period']['to'];
        $suffix = $allBranches ? " (semua cabang — {$storeCount} outlet)" : '';
        $lines = [
            "Rekap komisi barber **{$period}**{$suffix}:",
            '',
            "- Total komisi: **{$data['total_commission']}**",
            "- Transaksi dengan barber: **{$data['transaction_count']}**",
            "- Omzet dari transaksi barber: **{$data['gross_revenue']}**",
            "- Barber terlibat: **{$data['barber_count']}**",
            '',
            'Rincian per barber:',
        ];

        foreach ($data['rows'] as $idx => $row) {
            $lines[] = sprintf(
                '%d. **%s** — %d transaksi, omzet %s, komisi %s',
                $idx + 1,
                $row['barber_name'],
                $row['transaction_count'],
                $row['gross_revenue_formatted'],
                $row['commission_formatted']
            );
        }

        return implode("\n", $lines);
    }
}
