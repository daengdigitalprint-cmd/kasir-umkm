<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\EmployeePhotoService;
use App\Modules\Attendance\Support\AttendanceSettings;
use App\Modules\CashierPin\Services\CashierPinService;
use App\Modules\Payroll\Support\SetupChecklist;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /**
     * Halaman utama absensi: status kehadiran hari ini + ringkasan pengguna.
     */
    public function index(CashierPinService $pinService)
    {
        $store = $this->currentStore();
        $user = auth()->user();

        $employee = $this->attendance->resolveActiveEmployee($user, $store);
        // Absen terbuka (termasuk shift malam kemarin) atau absen hari ini.
        $today = $this->attendance->openAttendanceFor($store, $employee);

        // Admin/owner tidak perlu login via PIN — bypass gate.
        $userRole = method_exists($user, 'getRoleForActiveStore')
            ? $user->getRoleForActiveStore()
            : ($user->role ?? null);
        $isAdminLike = in_array($userRole, ['admin', 'owner'], true);

        // Daftar kasir untuk login PIN di perangkat bersama: sumbernya sama dengan
        // halaman login PIN POS (PIN disimpan di akun user, id = users.id).
        $cashiers = $pinService->getCashiersForStore($store->id)
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'position' => $pinService->roleLabel($member->pivot->role ?? null),
                'has_pin' => $member->hasCashierPin(),
            ])
            ->values();

        $effectiveLoginMode = $isAdminLike
            ? 'email'
            : ($store->cashier_login_mode ?? 'email');

        return Inertia::render('Attendance/Index', [
            'attendanceToday' => $this->serializeAttendance($today),
            'settings' => AttendanceSettings::forFrontend($store),
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'timezone' => $store->timezone ?: 'UTC',
            ],
            'cashiers' => $cashiers,
            'cashier_login_mode' => $effectiveLoginMode,
            // Panduan langkah awal hanya untuk pemilik/admin.
            'setup' => $isAdminLike ? SetupChecklist::for($store) : null,
            // Data tampilan ala aplikasi Android: profil, jadwal hari ini, rekap bulan, grafik 7 hari.
            'profile' => $this->profileData($store, $employee),
            'activeCashier' => session('active_cashier') ? [
                'employee_id' => session('active_cashier.employee_id'),
                'employee_name' => session('active_cashier.employee_name'),
            ] : null,
        ]);
    }

    /**
     * Check-in mandiri oleh karyawan (selfie + geolokasi).
     */
    public function clockIn(Request $request)
    {
        $store = $this->currentStore();
        $user = auth()->user();

        $geo = $this->validateGeo($request);
        $photo = $request->file('photo');

        $employee = $this->attendance->resolveActiveEmployee($user, $store);
        $attendance = $this->attendance->clockIn($store, $employee, $geo, $photo, $user);

        return back()->with('success', 'Check-in berhasil pada '.$attendance->clock_in?->format('H:i').'.');
    }

    /**
     * Check-out mandiri oleh karyawan.
     */
    public function clockOut(Request $request)
    {
        $store = $this->currentStore();
        $user = auth()->user();

        $geo = $this->validateGeo($request);
        $photo = $request->file('photo');

        $employee = $this->attendance->resolveActiveEmployee($user, $store);
        // Termasuk absen shift malam kemarin yang belum ditutup.
        $attendance = $this->attendance->openAttendanceFor($store, $employee);

        if (! $attendance) {
            return back()->withErrors([
                'clock_out' => 'Anda belum check-in hari ini.',
            ]);
        }

        $attendance = $this->attendance->clockOut($store, $attendance, $geo, $photo, $user);

        return back()->with('success', 'Check-out berhasil pada '.$attendance->clock_out?->format('H:i').'.');
    }

    /**
     * @return array{latitude: float|null, longitude: float|null, accuracy: float|null}
     */
    private function validateGeo(Request $request): array
    {
        $validated = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'photo' => 'nullable|file|mimes:jpeg,png,webp|max:5120',
        ]);

        return [
            'latitude' => isset($validated['latitude']) ? (float) $validated['latitude'] : null,
            'longitude' => isset($validated['longitude']) ? (float) $validated['longitude'] : null,
            'accuracy' => isset($validated['accuracy']) ? (float) $validated['accuracy'] : null,
        ];
    }

    private function currentStore(): Store
    {
        $store = auth()->user()->store ?? auth()->user()->stores()->first();

        if (! $store) {
            abort(404, 'Anda perlu membuat toko terlebih dahulu.');
        }

        return $store;
    }

    /**
     * Foto profil karyawan yang sedang login (bukan karyawan lain).
     */
    public function myPhoto(EmployeePhotoService $photos)
    {
        $store = $this->currentStore();
        $employee = $this->attendance->resolveActiveEmployee(auth()->user(), $store);

        return $photos->response($employee);
    }

    /**
     * @return array<string, mixed>
     */
    private function profileData(Store $store, Employee $employee): array
    {
        $tz = $store->timezone ?: 'UTC';
        $today = CarbonImmutable::now($tz);
        $schedule = $this->attendance->findScheduleFor($employee->id, $today, $store->id);
        $monthStart = $today->startOfMonth();
        $weekStart = $today->subDays(6)->startOfDay();
        $summary = $this->attendance->aggregate($employee, $monthStart->startOfDay(), $today->endOfMonth()->endOfDay());

        $days = Attendance::query()
            ->where('store_id', $store->id)
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$weekStart->toDateString(), $today->toDateString()])
            ->get()
            ->keyBy(fn (Attendance $a) => $a->work_date->toDateString());

        return [
            'name' => $employee->name,
            'position' => $employee->position,
            'photo_url' => $employee->photo_path
                ? '/attendance/my-photo?v='.EmployeePhotoService::version($employee)
                : null,
            'schedule' => $schedule ? [
                'shift_start' => substr((string) $schedule->shift_start, 0, 5),
                'shift_end' => substr((string) $schedule->shift_end, 0, 5),
            ] : null,
            'is_holiday' => Holiday::query()->where('store_id', $store->id)->whereDate('date', $today->toDateString())->exists(),
            'office' => AttendanceSettings::hasOfficeLocation($store) ? [
                'latitude' => AttendanceSettings::officeLatitude($store),
                'longitude' => AttendanceSettings::officeLongitude($store),
            ] : null,
            'month' => [
                'on_time' => max(0, (int) $summary['present_days'] - (int) $summary['late_days']),
                'late' => (int) $summary['late_days'],
                'absent' => (int) $summary['absent_days'],
                'leave' => (int) $summary['leave_days'],
            ],
            'week' => collect(range(6, 0))->map(function (int $back) use ($today, $days) {
                $date = $today->subDays($back)->toDateString();
                $row = $days->get($date);

                return [
                    'date' => $date,
                    'status' => $row?->status,
                    'hours' => $row?->hours_worked !== null ? (float) $row->hours_worked : 0.0,
                ];
            })->values(),
        ];
    }

    private function serializeAttendance(?Attendance $attendance): ?array
    {
        if (! $attendance) {
            return null;
        }

        return [
            'id' => $attendance->id,
            'work_date' => $attendance->work_date?->toDateString(),
            'clock_in' => $attendance->clock_in?->format('H:i'),
            'clock_out' => $attendance->clock_out?->format('H:i'),
            'status' => $attendance->status,
            'late_minutes' => $attendance->late_minutes,
            'hours_worked' => $attendance->hours_worked,
        ];
    }
}
