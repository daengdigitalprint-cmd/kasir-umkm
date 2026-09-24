<?php

declare(strict_types=1);

namespace App\Tools\Reports;

use App\Models\User;
use App\Services\AI\TransactionAnomalyDetector;
use App\Tools\AiTool;
use App\Tools\ToolResult;

class TransactionAnomalyTool extends AiTool
{
    public function getName(): string
    {
        return 'detect_transaction_anomalies';
    }

    public function getDescription(): string
    {
        return 'Audit anti-fraud: deteksi diskon tidak wajar, void berulang oleh kasir yang sama, dan shift kas dengan selisih hitungan fisik.';
    }

    public function getParameters(): array
    {
        return [
            'period' => [
                'type' => 'string',
                'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'custom'],
                'description' => 'Periode audit. Default: minggu berjalan.',
            ],
            'start_date' => [
                'type' => 'string',
                'description' => 'Tanggal mulai (Y-m-d) bila period=custom.',
            ],
            'end_date' => [
                'type' => 'string',
                'description' => 'Tanggal selesai (Y-m-d) bila period=custom.',
            ],
            'discount_threshold_pct' => [
                'type' => 'number',
                'description' => 'Ambang diskon mencurigakan dalam persen (default: 30).',
            ],
            'void_threshold' => [
                'type' => 'integer',
                'description' => 'Jumlah void minimum oleh kasir yang sama agar dianggap pola (default: 3).',
            ],
        ];
    }

    public function execute(array $args, User $user): ToolResult
    {
        if (! $user->store_id) {
            return ToolResult::error('Anda tidak memiliki toko. Hubungi admin untuk menghubungkan akun ke toko.');
        }

        [$from, $to] = $this->getDateRange($args);
        $storeIds = [(int) $user->store_id];

        $detector = new TransactionAnomalyDetector;

        $discountThreshold = (float) ($args['discount_threshold_pct'] ?? TransactionAnomalyDetector::DEFAULT_DISCOUNT_THRESHOLD_PCT);
        $voidThreshold = (int) ($args['void_threshold'] ?? TransactionAnomalyDetector::DEFAULT_VOID_THRESHOLD);

        $discountAnomalies = $detector->findDiscountAnomalies($storeIds, $from, $to, $discountThreshold);
        $voidPatterns = $detector->findRepeatedVoids($storeIds, $from, $to, $voidThreshold);
        $cashDifferences = $detector->findCashDifferences($storeIds, $from, $to);

        $totalFlags = count($discountAnomalies) + count($voidPatterns) + count($cashDifferences);

        $data = [
            'date_from' => $from->format('Y-m-d H:i'),
            'date_to' => $to->format('Y-m-d H:i'),
            'discount_anomalies' => $discountAnomalies,
            'void_patterns' => $voidPatterns,
            'cash_differences' => $cashDifferences,
            'total_flags' => $totalFlags,
        ];

        return ToolResult::success($data, $this->summaryMessage($data));
    }

    private function getDateRange(array $args): array
    {
        if (($args['period'] ?? null) === 'custom' && ! empty($args['start_date']) && ! empty($args['end_date'])) {
            $from = \Carbon\Carbon::parse($args['start_date'])->startOfDay();
            $to = \Carbon\Carbon::parse($args['end_date'])->endOfDay();

            return [$from, $to];
        }

        $period = $args['period'] ?? 'this_week';

        [$from, $to] = match ($period) {
            'today' => [\Carbon\Carbon::today(), \Carbon\Carbon::now()],
            'yesterday' => [\Carbon\Carbon::yesterday()->startOfDay(), \Carbon\Carbon::yesterday()->endOfDay()],
            'this_week' => [\Carbon\Carbon::now()->startOfWeek(), \Carbon\Carbon::now()],
            'last_week' => [
                \Carbon\Carbon::now()->subWeek()->startOfWeek(),
                \Carbon\Carbon::now()->subWeek()->endOfWeek(),
            ],
            'this_month' => [\Carbon\Carbon::now()->startOfMonth(), \Carbon\Carbon::now()],
            'last_month' => [
                \Carbon\Carbon::now()->subMonthNoOverflow()->startOfMonth(),
                \Carbon\Carbon::now()->subMonthNoOverflow()->endOfMonth(),
            ],
            default => [\Carbon\Carbon::now()->startOfWeek(), \Carbon\Carbon::now()],
        };

        return [$from, $to];
    }

    private function summaryMessage(array $data): string
    {
        if ($data['total_flags'] === 0) {
            return 'Tidak ada transaksi mencurigakan pada periode ini. Sistem memantau diskon besar, void berulang, dan selisih kas shift.';
        }

        $labels = [];
        $details = [];

        if ($data['discount_anomalies']) {
            $labels[] = 'Diskon tidak wajar';
            $details[] = count($data['discount_anomalies']).' transaksi';
        }
        if ($data['void_patterns']) {
            $labels[] = 'Void berulang';
            $details[] = count($data['void_patterns']).' kasir';
        }
        if ($data['cash_differences']) {
            $labels[] = 'Selisih kas shift';
            $details[] = count($data['cash_differences']).' shift';
        }

        return 'Ditemukan '.count($labels).' jenis indikasi: '.implode('; ', $labels)
            .' ('.implode('; ', $details).'). '
            .'Diskon ≥ '.TransactionAnomalyDetector::DEFAULT_DISCOUNT_THRESHOLD_PCT.'% subtotal dan void berulang perlu ditinjau manual. '
            .'Simpan temuan ini sebagai bagian dari audit anti-fraud toko Anda.';
    }
}
