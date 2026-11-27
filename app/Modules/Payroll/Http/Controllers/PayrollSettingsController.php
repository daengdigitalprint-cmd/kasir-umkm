<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Support\PayrollApproval;
use App\Services\ActivityLogger;
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
                'bpjs_health_wage_cap' => (float) $store->bpjs_health_wage_cap,
                'bpjs_jp_wage_cap' => (float) $store->bpjs_jp_wage_cap,
                'absence_deduction_enabled' => (bool) $store->absence_deduction_enabled,
                // PPh 21
                'pph21_enabled' => (bool) $store->pph21_enabled,
                // Persetujuan gaji oleh pemilik
                'owner_approval' => PayrollApproval::ownerOnly($store),
            ],
            'has_owner' => PayrollApproval::hasOwner($store),
        ]);
    }

    public function update(Request $request)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        $persen = 'numeric|min:0|max:100';
        $validated = $request->validate([
            // Potongan keterlambatan
            'late_penalty_per_day' => 'required|numeric|min:0|max:100000000',
            'late_penalty_per_minute' => 'required|numeric|min:0|max:100000000',
            'late_penalty_method' => 'required|in:none,per_day,per_minute',
            // Tarif lembur (pengali upah per jam)
            'overtime_rate_first_hour' => 'required|numeric|min:0|max:20',
            'overtime_rate_next_hour' => 'required|numeric|min:0|max:20',
            'overtime_rate_holiday' => 'required|numeric|min:0|max:20',
            // BPJS
            'bpjs_enabled' => 'required|boolean',
            'bpjs_health_employee' => 'required_if:bpjs_enabled,true|'.$persen,
            'bpjs_health_employer' => 'required_if:bpjs_enabled,true|'.$persen,
            'bpjs_jht_employee' => 'required_if:bpjs_enabled,true|'.$persen,
            'bpjs_jht_employer' => 'required_if:bpjs_enabled,true|'.$persen,
            'bpjs_jp_employee' => 'required_if:bpjs_enabled,true|'.$persen,
            'bpjs_jp_employer' => 'required_if:bpjs_enabled,true|'.$persen,
            'bpjs_jkk' => 'required_if:bpjs_enabled,true|'.$persen,
            'bpjs_jkm' => 'required_if:bpjs_enabled,true|'.$persen,
            'bpjs_health_wage_cap' => 'required|numeric|min:0|max:1000000000',
            'bpjs_jp_wage_cap' => 'required|numeric|min:0|max:1000000000',
            'absence_deduction_enabled' => 'required|boolean',
            // PPh 21
            'pph21_enabled' => 'required|boolean',
            'owner_approval' => 'sometimes|boolean',
        ], [
            'required' => ':attribute wajib diisi. Isi 0 bila tidak dipakai.',
            'required_if' => ':attribute wajib diisi selama BPJS diaktifkan.',
            'numeric' => ':attribute harus berupa angka.',
            'min' => ':attribute tidak boleh kurang dari :min.',
            'max' => ':attribute terlalu besar (paling banyak :max).',
            'in' => 'Pilihan :attribute tidak dikenali.',
            'boolean' => 'Pilihan :attribute tidak dikenali.',
        ], [
            'late_penalty_per_day' => 'Denda per hari telat',
            'late_penalty_per_minute' => 'Denda per menit telat',
            'late_penalty_method' => 'cara menghitung denda',
            'overtime_rate_first_hour' => 'Pengali lembur jam pertama',
            'overtime_rate_next_hour' => 'Pengali lembur jam berikutnya',
            'overtime_rate_holiday' => 'Pengali lembur hari libur',
            'bpjs_enabled' => 'BPJS',
            'bpjs_health_employee' => 'Iuran BPJS Kesehatan karyawan',
            'bpjs_health_employer' => 'Iuran BPJS Kesehatan perusahaan',
            'bpjs_jht_employee' => 'Iuran JHT karyawan',
            'bpjs_jht_employer' => 'Iuran JHT perusahaan',
            'bpjs_jp_employee' => 'Iuran JP karyawan',
            'bpjs_jp_employer' => 'Iuran JP perusahaan',
            'bpjs_jkk' => 'Iuran JKK',
            'bpjs_jkm' => 'Iuran JKM',
            'bpjs_health_wage_cap' => 'Batas upah BPJS Kesehatan',
            'bpjs_jp_wage_cap' => 'Batas upah Jaminan Pensiun',
            'absence_deduction_enabled' => 'potongan alpa',
            'pph21_enabled' => 'PPh 21',
            'owner_approval' => 'persetujuan gaji',
        ]);

        // Disimpan di settings JSON (bukan kolom tabel stores).
        if (array_key_exists('owner_approval', $validated)) {
            $ownerApproval = (bool) $validated['owner_approval'];
            unset($validated['owner_approval']);
            if ($ownerApproval !== PayrollApproval::ownerOnly($store)) {
                PayrollApproval::setOwnerOnly($store, $ownerApproval);
                ActivityLogger::log($store, 'payroll.settings_updated',
                    $ownerApproval ? 'Mengaktifkan persetujuan gaji oleh pemilik' : 'Menonaktifkan persetujuan gaji oleh pemilik', $store);
            }
        }

        $before = $store->only(array_keys($validated));
        $store->update($validated);
        $changes = array_intersect_key($store->getChanges(), $validated);

        if ($changes !== []) {
            ActivityLogger::log($store, 'payroll.settings_updated', 'Mengubah pengaturan payroll', $store, [
                'before' => array_intersect_key($before, $changes),
                'after' => $changes,
            ]);
        }

        return back()->with('success', 'Pengaturan gaji berhasil disimpan.');
    }
}
