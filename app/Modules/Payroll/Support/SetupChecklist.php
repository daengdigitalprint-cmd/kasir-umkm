<?php

namespace App\Modules\Payroll\Support;

use App\Models\ActivityLog;
use App\Models\Store;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Support\AttendanceSettings;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\SalaryComponent;

/**
 * Panduan langkah awal Absensi + Gaji untuk admin yang baru memakai modul.
 * Setiap langkah punya status selesai/belum dan tautan ke halaman yang tepat.
 */
final class SetupChecklist
{
    /**
     * @return array{steps: list<array<string, mixed>>, done: int, total: int, complete: bool}
     */
    public static function for(Store $store): array
    {
        $employees = Employee::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->get(['id', 'name']);
        $employeeIds = $employees->pluck('id');

        $withSchedule = WorkSchedule::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->whereIn('employee_id', $employeeIds)
            ->distinct()
            ->pluck('employee_id');

        $withSalary = SalaryComponent::query()
            ->where('store_id', $store->id)
            ->where('type', 'earning')
            ->whereIn('employee_id', $employeeIds)
            ->distinct()
            ->pluck('employee_id');

        $settingsReviewed = ActivityLog::query()
            ->where('store_id', $store->id)
            ->where('action', 'payroll.settings_updated')
            ->exists()
            || $store->bpjs_enabled
            || $store->pph21_enabled;

        $attendanceReady = AttendanceSettings::toggleEnabled($store)
            && (! AttendanceSettings::requireGeolocation($store) || AttendanceSettings::hasOfficeLocation($store));

        $missingNames = fn ($ids) => $employees->whereNotIn('id', $ids)->pluck('name')->take(3)->implode(', ');
        $count = $employees->count();

        $steps = [
            [
                'key' => 'attendance',
                'title' => 'Atur cara absen',
                'description' => 'Tentukan lokasi kantor, radius, dan apakah wajib selfie.',
                'href' => '/attendance/settings',
                'done' => $attendanceReady,
            ],
            [
                'key' => 'employees',
                'title' => 'Tambah karyawan',
                'description' => $count > 0 ? "{$count} karyawan aktif." : 'Daftarkan siapa saja yang digaji dan absen.',
                'href' => '/attendance/employees',
                'done' => $count > 0,
            ],
            [
                'key' => 'schedules',
                'title' => 'Buat jadwal kerja',
                'description' => $count === 0
                    ? 'Jam masuk–pulang dipakai untuk menghitung telat, alpa, dan lembur.'
                    : "{$withSchedule->count()} dari {$count} karyawan sudah punya jadwal."
                        .($withSchedule->count() < $count ? ' Belum: '.$missingNames($withSchedule).'.' : ''),
                'href' => '/attendance/schedules',
                'done' => $count > 0 && $withSchedule->count() >= $count,
            ],
            [
                'key' => 'salaries',
                'title' => 'Isi komponen gaji',
                'description' => $count === 0
                    ? 'Gaji pokok, tunjangan, dan potongan tetap tiap karyawan.'
                    : "{$withSalary->count()} dari {$count} karyawan sudah punya gaji."
                        .($withSalary->count() < $count ? ' Belum: '.$missingNames($withSalary).'.' : ''),
                'href' => '/payroll/salaries',
                'done' => $count > 0 && $withSalary->count() >= $count,
            ],
            [
                'key' => 'settings',
                'title' => 'Cek aturan gaji',
                'description' => 'Denda telat, tarif lembur, BPJS, dan PPh 21.',
                'href' => '/payroll/settings',
                'done' => $settingsReviewed,
            ],
            [
                'key' => 'period',
                'title' => 'Hitung gaji pertama',
                'description' => 'Buat periode (mis. satu bulan), lalu tekan Hitung.',
                'href' => '/payroll',
                'done' => PayrollPeriod::query()->where('store_id', $store->id)->whereHas('runs')->exists(),
            ],
        ];

        $done = collect($steps)->where('done', true)->count();

        return [
            'steps' => $steps,
            'done' => $done,
            'total' => count($steps),
            'complete' => $done === count($steps),
        ];
    }
}
