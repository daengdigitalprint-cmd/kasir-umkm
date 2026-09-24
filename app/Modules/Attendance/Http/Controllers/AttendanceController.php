<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Support\AttendanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /**
     * Halaman utama absensi: status kehadiran hari ini + ringkasan pengguna.
     */
    public function index()
    {
        $store = $this->currentStore();
        $user = auth()->user();

        $employee = $this->attendance->resolveActiveEmployee($user, $store);
        $today = $this->todayAttendance($store, $employee);

        // Admin/owner tidak perlu login via PIN — bypass gate.
        $userRole = method_exists($user, 'getRoleForActiveStore')
            ? $user->getRoleForActiveStore()
            : ($user->role ?? null);
        $isAdminLike = in_array($userRole, ['admin', 'owner'], true);

        $cashiers = Employee::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->whereNotNull('pin_hash')
            ->where(function ($q) use ($store) {
                // Karyawan tanpa akun user, atau akun dengan role kasir di toko ini.
                $q->whereNull('user_id')
                    ->orWhereHas('user.stores', function ($sq) use ($store) {
                        $sq->where('store_user.store_id', $store->id)
                            ->where('store_user.role', 'cashier');
                    });
            })
            ->orderBy('name')
            ->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'position' => $e->position,
                'has_pin' => $e->hasPin(),
            ]);

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
        $attendance = $this->todayAttendance($store, $employee);

        if (! $attendance) {
            return back()->withErrors([
                'clock_out' => 'Anda belum check-in hari ini.',
            ]);
        }

        $this->attendance->clockOut($store, $attendance, $geo, $photo, $user);

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

    private function todayAttendance(Store $store, Employee $employee): ?Attendance
    {
        $today = CarbonImmutable::now($store->timezone ?: 'UTC')->toDateString();

        return Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $today)
            ->first();
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
