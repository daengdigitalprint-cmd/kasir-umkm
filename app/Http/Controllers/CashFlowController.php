<?php

namespace App\Http\Controllers;

use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CashFlowController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            abort(403, 'Tidak ada toko aktif.');
        }

        // Default ke Bulan Ini
        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));

        // Ambil semua shift yang sudah ditutup dalam periode
        $shifts = CashShift::where('store_id', $store->id)
            ->whereNotNull('closed_at')
            ->whereDate('closed_at', '>=', $startDate)
            ->whereDate('closed_at', '<=', $endDate)
            ->with(['user:id,name'])
            ->orderByDesc('closed_at')
            ->get();

        // Ambil data transaksi per shift
        $shiftIds = $shifts->pluck('id');
        $txByShift = Transaction::whereIn('cash_shift_id', $shiftIds)
            ->where('status', 'completed')
            ->selectRaw('cash_shift_id, payment_method, COUNT(*) as count, SUM(total_amount) as total, SUM(cash_amount) as cash_total')
            ->groupBy('cash_shift_id', 'payment_method')
            ->get()
            ->groupBy('cash_shift_id');

        $cashInByShift = CashTransaction::whereIn('cash_shift_id', $shiftIds)
            ->where('type', 'in')
            ->selectRaw('cash_shift_id, SUM(amount) as total')
            ->groupBy('cash_shift_id')
            ->pluck('total', 'cash_shift_id');

        $cashOutByShift = CashTransaction::whereIn('cash_shift_id', $shiftIds)
            ->where('type', 'out')
            ->selectRaw('cash_shift_id, SUM(amount) as total')
            ->groupBy('cash_shift_id')
            ->pluck('total', 'cash_shift_id');

        // Format data per shift
        $formattedShifts = $shifts->map(function ($shift) use ($txByShift, $cashInByShift, $cashOutByShift) {
            $openingCash = (float) $shift->opening_cash;
            $closingCash = (float) ($shift->closing_cash_counted ?? 0);
            $expectedCash = (float) ($shift->expected_cash ?? 0);
            $difference = (float) ($shift->difference_amount ?? 0);

            // Penjualan per metode
            $salesData = $txByShift[$shift->id] ?? collect();
            $salesCash = (float) $salesData->sum('cash_total');
            $salesNonCash = (float) $salesData->sum('total') - $salesCash;
            $totalSales = $salesCash + $salesNonCash;
            $totalTxCount = (int) $salesData->sum('count');

            // Cash in/out
            $cashIn = (float) ($cashInByShift[$shift->id] ?? 0);
            $cashOut = (float) ($cashOutByShift[$shift->id] ?? 0);

            // Perhitungan kas teoritis
            $theoreticalCash = $openingCash + $salesCash + $cashIn - $cashOut;

            return [
                'id' => $shift->id,
                'shift_number' => $shift->shift_number,
                'cashier' => $shift->user?->name ?? '—',
                'opened_at' => $shift->opened_at?->format('d/m/Y H:i') ?? '',
                'closed_at' => $shift->closed_at?->format('d/m/Y H:i') ?? '',
                'opening_cash' => $openingCash,
                'closing_cash_counted' => $closingCash,
                'expected_cash' => $expectedCash,
                'theoretical_cash' => $theoreticalCash,
                'difference' => $difference,
                'total_sales' => $totalSales,
                'sales_cash' => $salesCash,
                'sales_non_cash' => $salesNonCash,
                'tx_count' => $totalTxCount,
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
                // Breakdown metode bayar
                'payment_methods' => $salesData->map(fn ($r) => [
                    'method' => $r->payment_method,
                    'count' => (int) $r->count,
                    'total' => (float) $r->total,
                ])->values(),
            ];
        });

        // Summary totals
        $summary = [
            'total_shifts' => $shifts->count(),
            'total_sales' => $formattedShifts->sum('total_sales'),
            'total_sales_cash' => $formattedShifts->sum('sales_cash'),
            'total_sales_non_cash' => $formattedShifts->sum('sales_non_cash'),
            'total_cash_in' => $formattedShifts->sum('cash_in'),
            'total_cash_out' => $formattedShifts->sum('cash_out'),
            'total_difference' => $formattedShifts->sum('difference'),
            'total_tx_count' => $formattedShifts->sum('tx_count'),
            'avg_sales_per_shift' => $shifts->count() > 0 ? $formattedShifts->sum('total_sales') / $shifts->count() : 0,
        ];

        return Inertia::render('Reports/CashFlow', [
            'shifts' => $formattedShifts,
            'summary' => $summary,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }
}
