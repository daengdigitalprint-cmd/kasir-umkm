<?php

namespace App\Modules\Payroll\Support;

use App\Models\Store;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Slip gaji PDF satu karyawan untuk satu periode.
 * Dipakai admin (semua karyawan) dan karyawan (slip miliknya sendiri).
 */
final class PayslipPdf
{
    public static function download(Store $store, PayrollPeriod $period, PayrollRun $run): Response
    {
        $run->loadMissing('employee');
        $b = $run->component_breakdown ?? [];

        $earnings = collect($b['earnings'] ?? [])
            ->map(fn ($item) => ['label' => $item['name'], 'amount' => (float) $item['amount']]);

        if ((float) $run->overtime_pay > 0) {
            $earnings->push(['label' => 'Lembur ('.rtrim(rtrim(number_format((float) $run->overtime_hours, 2, ',', '.'), '0'), ',').' jam)', 'amount' => (float) $run->overtime_pay]);
        }

        if ((float) ($b['absence']['total'] ?? 0) > 0) {
            $earnings->push(['label' => 'Potongan alpa / cuti tanpa gaji ('.$b['absence']['days'].' hari)', 'amount' => -1 * (float) $b['absence']['total']]);
        }

        $deductions = collect($b['deductions'] ?? [])
            ->map(fn ($item) => ['label' => $item['name'], 'amount' => (float) $item['amount']]);

        if ((float) ($b['late_penalty']['total'] ?? 0) > 0) {
            $deductions->push(['label' => 'Denda keterlambatan', 'amount' => (float) $b['late_penalty']['total']]);
        }

        foreach (['health' => 'BPJS Kesehatan', 'jht' => 'BPJS JHT', 'jp' => 'BPJS Jaminan Pensiun'] as $key => $label) {
            if ((float) ($b['bpjs'][$key]['employee'] ?? 0) > 0) {
                $deductions->push(['label' => $label.' (karyawan)', 'amount' => (float) $b['bpjs'][$key]['employee']]);
            }
        }

        if ((float) ($b['tax']['monthly_tax'] ?? 0) > 0) {
            $deductions->push(['label' => 'PPh 21', 'amount' => (float) $b['tax']['monthly_tax']]);
        }

        $pdf = Pdf::loadView('pdf.payslip', [
            'store' => $store,
            'period' => $period,
            'run' => $run,
            'employee' => $run->employee,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'refund' => (float) ($b['tax']['refund'] ?? 0),
            'shortfall' => (float) ($b['shortfall'] ?? 0),
            'summary' => $run->attendance_summary ?? [],
            'isFinal' => $period->status !== PayrollPeriod::STATUS_DRAFT,
        ])->setPaper('a5', 'portrait');

        $filename = 'slip-gaji-'.Str::slug($run->employee?->name ?? 'karyawan').'-'.Str::slug($period->name).'.pdf';

        return $pdf->download($filename);
    }
}
