<?php

namespace App\NativeComponents\Absensi;

use App\Models\Store;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Services\AttendanceService;
use App\NativeComponents\Concerns\GuardsAttendancePayroll;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

/**
 * Absen yang dicatat pemilik/admin/manager atas nama karyawan dari aplikasi mobile.
 * Penulisan lewat AttendanceService agar zona waktu toko, jadwal, telat, jam kerja,
 * dan penguncian sama persis dengan absen web.
 */
class Index extends NativeComponent
{
    use GuardsAttendancePayroll;

    public int $totalKaryawan = 0;

    public int $hadirHariIni = 0;

    public int $belumAbsen = 0;

    public bool $showClockModal = false;

    public int $selectedEmployeeId = 0;

    public string $selectedEmployeeName = '';

    public string $clockType = 'in';

    public string $clockError = '';

    public function navTitle(): string
    {
        return 'Absensi';
    }

    public function mount(): void
    {
        $this->loadStats();
    }

    public function loadStats(): void
    {
        [, $store, $denied] = $this->guard('attendance');
        if ($denied !== '') {
            return;
        }

        $this->totalKaryawan = Employee::where('store_id', $store->id)
            ->where('is_active', true)
            ->count();

        $this->hadirHariIni = Attendance::where('store_id', $store->id)
            ->whereDate('work_date', $this->storeToday($store))
            ->whereIn('status', [Attendance::STATUS_PRESENT, Attendance::STATUS_LATE])
            ->count();

        $this->belumAbsen = max(0, $this->totalKaryawan - $this->hadirHariIni);
    }

    public function openClockModal(int $employeeId, string $name, string $type = 'in'): void
    {
        $this->selectedEmployeeId = $employeeId;
        $this->selectedEmployeeName = $name;
        $this->clockType = $type === 'out' ? 'out' : 'in';
        $this->clockError = '';
        $this->showClockModal = true;
    }

    public function closeClockModal(): void
    {
        $this->showClockModal = false;
        $this->clockError = '';
    }

    public function saveClock(): void
    {
        [$user, $store, $denied] = $this->guard('attendance');
        if ($denied !== '') {
            $this->clockError = $denied;

            return;
        }

        if (! $this->selectedEmployeeId) {
            return;
        }

        // Karyawan wajib milik toko aktif dan masih aktif.
        $employee = Employee::where('store_id', $store->id)
            ->where('is_active', true)
            ->find($this->selectedEmployeeId);

        if (! $employee) {
            $this->clockError = 'Karyawan tidak ditemukan di toko ini.';

            return;
        }

        try {
            app(AttendanceService::class)->recordByAdmin($store, $employee, $this->clockType, $user);
        } catch (ValidationException $e) {
            $this->clockError = collect($e->errors())->flatten()->first() ?? 'Absen gagal disimpan.';

            return;
        }

        $this->loadStats();
        $this->showClockModal = false;
        $this->clockError = '';
    }

    public function render(): Element|View
    {
        [, $store, $denied] = $this->guard('attendance');
        if ($denied !== '') {
            return $this->deniedView('Akses Absensi Ditolak', $denied);
        }

        $today = $this->storeToday($store);

        $employees = Employee::where('store_id', $store->id)
            ->where('is_active', true)
            ->with(['attendances' => function ($q) use ($today) {
                $q->whereDate('work_date', $today);
            }])
            ->orderBy('name')
            ->get();

        return view('native.absensi.index', compact('employees'));
    }

    private function storeToday(Store $store): string
    {
        return CarbonImmutable::now($store->timezone ?: 'UTC')->toDateString();
    }
}
