<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AgingPayablesController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            abort(403, 'Tidak ada toko aktif.');
        }

        $asOf = $request->get('as_of', Carbon::now()->format('Y-m-d'));
        $asOfDate = Carbon::parse($asOf);

        $purchases = Purchase::where('store_id', $store->id)
            ->where('is_paid', false)
            ->whereNotNull('total_amount')
            ->with('supplier:id,name')
            ->orderBy('due_date')
            ->get();

        $totalOutstanding = 0;
        $overdue = 0;
        $current = 0;

        // Aging buckets: 0-30, 31-60, 61-90, >90 hari
        $buckets = [
            'not_due' => ['label' => 'Belum jatuh tempo', 'total' => 0, 'count' => 0],
            '0-30' => ['label' => '0-30 hari', 'total' => 0, 'count' => 0],
            '31-60' => ['label' => '31-60 hari', 'total' => 0, 'count' => 0],
            '61-90' => ['label' => '61-90 hari', 'total' => 0, 'count' => 0],
            'over_90' => ['label' => '>90 hari', 'total' => 0, 'count' => 0],
        ];

        $rows = $purchases->values()->map(function ($p) use ($asOfDate, &$totalOutstanding, &$overdue, &$current, &$buckets) {
            $total = (float) $p->total_amount;
            $paid = (float) $p->amount_paid;
            $remaining = max(0, $total - $paid);
            $totalOutstanding += $remaining;

            $due = $p->due_date;
            $ageDays = $due ? $asOfDate->diffInDays(Carbon::parse($due), false) : null;

            $status = 'Belum jatuh tempo';
            $agingBucket = 'not_due';

            if ($ageDays !== null && $ageDays < 0) {
                $absDays = abs($ageDays);
                $status = 'Jatuh tempo ('.$absDays.' hari lalu)';
                $overdue += $remaining;

                if ($absDays <= 30) {
                    $agingBucket = '0-30';
                } elseif ($absDays <= 60) {
                    $agingBucket = '31-60';
                } elseif ($absDays <= 90) {
                    $agingBucket = '61-90';
                } else {
                    $agingBucket = 'over_90';
                }
            } elseif ($ageDays !== null && $ageDays === 0) {
                $status = 'Jatuh tempo hari ini';
                $current += $remaining;
                $agingBucket = '0-30';
            } else {
                $current += $remaining;
            }

            $buckets[$agingBucket]['total'] += $remaining;
            $buckets[$agingBucket]['count']++;

            return [
                'id' => $p->id,
                'purchase_number' => $p->purchase_number,
                'supplier_name' => $p->supplier?->name ?? '—',
                'purchased_at' => $p->purchased_at instanceof Carbon ? $p->purchased_at->format('d/m/Y') : '',
                'due_date' => $due instanceof Carbon ? $due->format('d/m/Y') : ($due ?? '—'),
                'total_amount' => $total,
                'amount_paid' => $paid,
                'remaining' => $remaining,
                'status' => $status,
                'aging_days' => $ageDays !== null ? abs($ageDays) : null,
            ];
        });

        return Inertia::render('Reports/AgingPayables', [
            'purchases' => $rows,
            'buckets' => $buckets,
            'summary' => [
                'total_outstanding' => $totalOutstanding,
                'overdue' => $overdue,
                'current' => $current,
                'count' => $purchases->count(),
            ],
            'filters' => ['as_of' => $asOf],
        ]);
    }
}
