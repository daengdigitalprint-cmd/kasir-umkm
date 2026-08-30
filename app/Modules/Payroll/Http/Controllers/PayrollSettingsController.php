<?php

namespace App\Modules\Payroll\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class PayrollSettingsController extends AdminPayrollController
{
    public function index()
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        return Inertia::render('Payroll/Settings', [
            'settings' => [
                // Potongan keterlambatan
                'late_penalty_per_day' => (float) $store->late_penalty_per_day,
                'late_penalty_per_minute' => (float) $store->late_penalty_per_minute,
                'late_penalty_method' => $store->late_penalty_method ?? 'per_day',
                // Tarif lembur
                'overtime_rate_first_hour' => (float) $store->overtime_rate_first_hour,
                'overtime_rate_next_hour' => (float) $store->overtime_rate_next_hour,
                'overtime_rate_holiday' => (float) $store->overtime_rate_holiday,
                // BPJS
                'bpjs_enabled' => (bool) $store->bpjs_enabled,
                'bpjs_health_employee' => (float) $store->bpjs_health_employee,
                'bpjs_health_employer' => (float) $store->bpjs_health_employer,
                'bpjs_jht_employee' => (float) $store->bpjs_jht_employee,
                'bpjs_jht_employer' => (float) $store->bpjs_jht_employer,
                'bpjs_jp_employee' => (float) $store->bpjs_jp_employee,
                'bpjs_jp_employer' => (float) $store->bpjs_jp_employer,
                'bpjs_jkk' => (float) $store->bpjs_jkk,
                'bpjs_jkm' => (float) $store->bpjs_jkm,
                // PPh 21
                'pph21_enabled' => (bool) $store->pph21_enabled,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        $validated = $request->validate([
            // Potongan keterlambatan
            'late_penalty_per_day' => 'required|numeric|min:0',
            'late_penalty_per_minute' => 'required|numeric|min:0',
            'late_penalty_method' => 'required|in:none,per_day,per_minute',
            // Tarif lembur
            'overtime_rate_first_hour' => 'required|numeric|min:0',
            'overtime_rate_next_hour' => 'required|numeric|min:0',
            'overtime_rate_holiday' => 'required|numeric|min:0',
            // BPJS
            'bpjs_enabled' => 'required|boolean',
            'bpjs_health_employee' => 'required_if:bpjs_enabled,true|numeric|min:0',
            'bpjs_health_employer' => 'required_if:bpjs_enabled,true|numeric|min:0',
            'bpjs_jht_employee' => 'required_if:bpjs_enabled,true|numeric|min:0',
            'bpjs_jht_employer' => 'required_if:bpjs_enabled,true|numeric|min:0',
            'bpjs_jp_employee' => 'required_if:bpjs_enabled,true|numeric|min:0',
            'bpjs_jp_employer' => 'required_if:bpjs_enabled,true|numeric|min:0',
            'bpjs_jkk' => 'required_if:bpjs_enabled,true|numeric|min:0',
            'bpjs_jkm' => 'required_if:bpjs_enabled,true|numeric|min:0',
            // PPh 21
            'pph21_enabled' => 'required|boolean',
        ]);

        $store->update($validated);

        return back()->with('success', 'Pengaturan payroll berhasil disimpan.');
    }
}
