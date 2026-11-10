<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Models\LeaveRequest;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\EmployeePhotoService;
use App\Modules\Attendance\Services\GeolocationService;
use App\Modules\Attendance\Support\AttendanceSettings;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Support\PayslipPdf;
use App\Services\ActivityLogger;
use App\Services\AttendancePayrollAddOnService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * API aplikasi Absensi Android (Flutter) untuk karyawan.
 *
 * Semua aturan (jadwal, telat, radius, selfie, cuti, payroll) memakai service yang sama
 * dengan versi web, jadi hasil absen dari HP dan dari web identik.
 *
 * Header wajib dari aplikasi: `X-Device-Id` (ID unik HP) dan opsional `X-Device-Name`.
 * Satu karyawan terikat ke satu HP; HP pertama terdaftar otomatis, ganti HP dilepas admin.
 */
class AttendanceApiController extends Controller
{
    public const LEAVE_TYPES = [
        'annual' => 'Cuti tahunan',
        'sick' => 'Sakit',
        'maternity' => 'Cuti melahirkan',
        'marriage' => 'Cuti menikah',
        'bereavement' => 'Cuti duka',
        'unpaid' => 'Tanpa gaji',
        'other' => 'Lainnya',
    ];

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly GeolocationService $geolocation,
    ) {}

    /**
     * Data beranda: profil, aturan absen, jadwal & status hari ini, jadwal mingguan (untuk pengingat).
     */
    public function me(Request $request): JsonResponse
    {
        [$store, $employee] = $this->context($request);
        $device = $this->deviceStatus($request, $employee);
        $today = CarbonImmutable::now($store->timezone ?: 'UTC');
        $schedule = $this->attendance->findScheduleFor($employee->id, $today, $store->id);
        $holiday = Holiday::query()->where('store_id', $store->id)->whereDate('date', $today->toDateString())->first();
        $current = $this->attendance->openAttendanceFor($store, $employee);

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'position' => $employee->position,
                'photo_url' => $employee->photo_path
                    ? '/api/v1/attendance/profile/photo?v='.EmployeePhotoService::version($employee)
                    : null,
            ],
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'timezone' => $store->timezone ?: 'UTC',
            ],
            'policy' => [
                'require_selfie' => AttendanceSettings::requireSelfie($store),
                'require_geolocation' => AttendanceSettings::requireGeolocation($store),
                'radius_meters' => AttendanceSettings::radiusMeters($store),
                'max_accuracy_meters' => max(150, AttendanceSettings::radiusMeters($store)),
                'grace_minutes' => AttendanceSettings::graceMinutes($store),
                'office' => AttendanceSettings::hasOfficeLocation($store) ? [
                    'latitude' => AttendanceSettings::officeLatitude($store),
                    'longitude' => AttendanceSettings::officeLongitude($store),
                ] : null,
            ],
            'today' => [
                'date' => $today->toDateString(),
                'is_holiday' => (bool) $holiday,
                'holiday_name' => $holiday?->name,
                'schedule' => $schedule ? $this->serializeSchedule($schedule) : null,
            ],
            'attendance' => $current ? $this->serializeAttendance($current) : null,
            'next_action' => match (true) {
                ! $current || ! $current->clock_in => 'clock_in',
                ! $current->clock_out => 'clock_out',
                default => 'done',
            },
            'week_schedule' => WorkSchedule::query()
                ->where('store_id', $store->id)
                ->where('employee_id', $employee->id)
                ->where('is_active', true)
                ->get()
                ->map(fn (WorkSchedule $s) => $this->serializeSchedule($s))
                ->values(),
            'device' => $device,
            // Semua outlet tempat karyawan bisa absen masuk. Aplikasi memilih outlet terdekat
            // dari daftar ini untuk cek jarak; server tetap memvalidasi ulang.
            'outlets' => $this->candidateEmployees($request->user())
                ->map(fn (Employee $e) => [
                    'id' => $e->store->id,
                    'name' => $e->store->name,
                    'require_selfie' => AttendanceSettings::requireSelfie($e->store),
                    'require_geolocation' => AttendanceSettings::requireGeolocation($e->store),
                    'radius_meters' => AttendanceSettings::radiusMeters($e->store),
                    'office' => AttendanceSettings::hasOfficeLocation($e->store) ? [
                        'latitude' => AttendanceSettings::officeLatitude($e->store),
                        'longitude' => AttendanceSettings::officeLongitude($e->store),
                    ] : null,
                ])
                ->values(),
            'server_time' => $today->toIso8601String(),
        ]);
    }

    public function clockIn(Request $request): JsonResponse
    {
        [$geo, $photo] = $this->validateCapture($request);
        [$store, $employee] = $this->context($request, $geo);
        $this->ensureDevice($request, $employee, $store);

        $attendance = $this->attendance->clockIn($store, $employee, $geo, $photo, $request->user());

        return response()->json([
            'message' => 'Absen masuk berhasil pukul '.$attendance->clock_in?->format('H:i').'.',
            'attendance' => $this->serializeAttendance($attendance->fresh()),
        ]);
    }

    public function clockOut(Request $request): JsonResponse
    {
        [$store, $employee] = $this->context($request);
        $this->ensureDevice($request, $employee, $store);
        [$geo, $photo] = $this->validateCapture($request);

        $open = $this->attendance->openAttendanceFor($store, $employee);
        if (! $open?->clock_in) {
            throw ValidationException::withMessages(['clock_out' => 'Anda belum absen masuk hari ini.']);
        }

        $attendance = $this->attendance->clockOut($store, $open, $geo, $photo, $request->user());

        return response()->json([
            'message' => 'Absen pulang berhasil pukul '.$attendance->clock_out?->format('H:i').'.',
            'attendance' => $this->serializeAttendance($attendance->fresh()),
        ]);
    }

    /**
     * Riwayat satu bulan untuk kalender + rekap.
     */
    public function history(Request $request): JsonResponse
    {
        [$store, $employee] = $this->context($request);
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $month = isset($validated['month'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['month'].'-01', $store->timezone ?: 'UTC')
            : CarbonImmutable::now($store->timezone ?: 'UTC');
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();

        $rows = Attendance::query()
            ->where('store_id', $store->id)
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('work_date')
            ->get();

        return response()->json([
            'month' => $from->format('Y-m'),
            'days' => $rows->map(fn (Attendance $a) => $this->serializeAttendance($a))->values(),
            'holidays' => Holiday::query()
                ->where('store_id', $store->id)
                ->whereDate('date', '>=', $from->toDateString())
                ->whereDate('date', '<=', $to->toDateString())
                ->get()
                ->map(fn (Holiday $h) => ['date' => $h->date->toDateString(), 'name' => $h->name])
                ->values(),
            'summary' => collect($this->attendance->aggregate($employee, $from->startOfDay(), $to->endOfDay()))
                ->except('overtime_by_day'),
        ]);
    }

    public function leaves(Request $request): JsonResponse
    {
        [$store, $employee] = $this->context($request);

        return response()->json([
            'types' => collect(self::LEAVE_TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'items' => LeaveRequest::query()
                ->where('store_id', $store->id)
                ->where('employee_id', $employee->id)
                ->with('approver:id,name')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn (LeaveRequest $l) => [
                    'id' => $l->id,
                    'type' => $l->leave_type,
                    'type_label' => self::LEAVE_TYPES[$l->leave_type] ?? $l->leave_type,
                    'start_date' => $l->start_date->toDateString(),
                    'end_date' => $l->end_date->toDateString(),
                    'reason' => $l->reason,
                    'status' => $l->status,
                    'reviewed_by' => $l->approver?->name,
                ])
                ->values(),
        ]);
    }

    public function storeLeave(Request $request): JsonResponse
    {
        [$store, $employee] = $this->context($request);
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(self::LEAVE_TYPES))],
            'start_date' => ['required', 'date', 'before_or_equal:end_date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $leave = LeaveRequest::create([
            'store_id' => $store->id,
            'employee_id' => $employee->id,
            'leave_type' => $validated['type'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json(['message' => 'Pengajuan cuti terkirim. Menunggu persetujuan.', 'id' => $leave->id], 201);
    }

    public function overtimes(Request $request): JsonResponse
    {
        [$store, $employee] = $this->context($request);

        return response()->json([
            'items' => OvertimeRequest::query()
                ->where('store_id', $store->id)
                ->where('employee_id', $employee->id)
                ->with('approver:id,name')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn (OvertimeRequest $o) => [
                    'id' => $o->id,
                    'work_date' => $o->work_date->toDateString(),
                    'hours' => (float) $o->hours,
                    'reason' => $o->reason,
                    'status' => $o->status,
                    'reviewed_by' => $o->approver?->name,
                ])
                ->values(),
        ]);
    }

    public function storeOvertime(Request $request): JsonResponse
    {
        [$store, $employee] = $this->context($request);
        $today = CarbonImmutable::now($store->timezone ?: 'UTC')->toDateString();
        $validated = $request->validate([
            'work_date' => ['required', 'date', 'before_or_equal:'.$today],
            'hours' => ['required', 'numeric', 'min:0.5', 'max:24'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->attendance->assertNoAutoOvertime($employee->id, $validated['work_date']);

        $overtime = OvertimeRequest::create([
            'store_id' => $store->id,
            'employee_id' => $employee->id,
            'work_date' => $validated['work_date'],
            'hours' => $validated['hours'],
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json(['message' => 'Pengajuan lembur terkirim. Menunggu persetujuan.', 'id' => $overtime->id], 201);
    }

    /**
     * Foto profil karyawan sendiri.
     */
    public function profilePhoto(Request $request, EmployeePhotoService $photos): Response
    {
        [, $employee] = $this->context($request);

        return $photos->response($employee);
    }

    public function uploadProfilePhoto(Request $request, EmployeePhotoService $photos): JsonResponse
    {
        [$store, $employee] = $this->context($request);
        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:4096', 'dimensions:min_width=96,min_height=96'],
        ], [
            'photo.dimensions' => 'Foto terlalu kecil.',
            'photo.max' => 'Ukuran foto maksimal 4 MB.',
        ]);

        $photos->store($employee, $request->file('photo'));
        ActivityLogger::log($store, 'employee.photo_updated', "{$employee->name} mengganti foto profil", $employee, [], $request->user());

        return response()->json([
            'message' => 'Foto profil diperbarui.',
            'photo_url' => '/api/v1/attendance/profile/photo?v='.EmployeePhotoService::version($employee),
        ]);
    }

    public function deleteProfilePhoto(Request $request, EmployeePhotoService $photos): JsonResponse
    {
        [, $employee] = $this->context($request);
        $photos->remove($employee);

        return response()->json(['message' => 'Foto profil dihapus.', 'photo_url' => null]);
    }

    public function payslips(Request $request): JsonResponse
    {
        [$store, $employee] = $this->context($request);

        $runs = PayrollRun::query()
            ->where('store_id', $store->id)
            ->where('employee_id', $employee->id)
            ->whereHas('period', fn ($q) => $q->whereIn('status', [PayrollPeriod::STATUS_APPROVED, PayrollPeriod::STATUS_PAID]))
            ->with('period')
            ->get()
            ->sortByDesc(fn (PayrollRun $run) => $run->period->period_end)
            ->values();

        return response()->json([
            'items' => $runs->map(fn (PayrollRun $run) => [
                'id' => $run->id,
                'period' => $run->period->name,
                'period_start' => $run->period->period_start->toDateString(),
                'period_end' => $run->period->period_end->toDateString(),
                'status' => $run->period->status,
                'payment_date' => $run->period->payment_date?->toDateString(),
                'gross_salary' => (float) $run->gross_salary,
                'deductions' => (float) $run->deductions,
                'net_salary' => (float) $run->net_salary,
            ]),
        ]);
    }

    public function payslipPdf(Request $request, PayrollRun $run): Response
    {
        [$store, $employee] = $this->context($request);

        abort_if((int) $run->store_id !== (int) $store->id || (int) $run->employee_id !== (int) $employee->id, 404);
        $period = $run->period;
        abort_if(! $period || $period->isDraft(), 404);

        return PayslipPdf::download($store, $period, $run);
    }

    /**
     * @return array{0: Store, 1: Employee}
     */
    /**
     * Tentukan outlet + data karyawan untuk request ini.
     *
     * Outlet TIDAK diambil dari toko yang sedang dipilih di web (users.store_id), karena
     * kasir/pemilik bisa berganti outlet di web kapan saja. Urutannya:
     * 1. Outlet dengan absen masuk yang belum ditutup (absen pulang selalu ke outlet yang sama).
     * 2. Bila karyawan hanya terdaftar di satu outlet aktif: outlet itu.
     * 3. Saat absen masuk (ada GPS): outlet terdekat yang radiusnya mencakup posisi karyawan.
     * 4. Selain itu: outlet yang sudah punya absen hari ini, toko aktif di web, outlet utama, lalu yang pertama.
     *
     * @param  array{latitude: float|null, longitude: float|null}|null  $geo
     * @return array{0: Store, 1: Employee}
     */
    private function context(Request $request, ?array $geo = null): array
    {
        /** @var User $user */
        $user = $request->user();
        $candidates = $this->candidateEmployees($user);

        $pick = fn (Employee $employee) => [$employee->store, $employee];

        if ($candidates->count() === 1) {
            return $pick($candidates->first());
        }

        $today = [];
        foreach ($candidates as $employee) {
            $attendance = $this->attendance->openAttendanceFor($employee->store, $employee);
            if ($attendance?->clock_in && ! $attendance->clock_out) {
                return $pick($employee);
            }
            if ($attendance) {
                $today[] = $employee;
            }
        }

        if ($geo !== null) {
            return $pick($this->outletForPosition($candidates, $geo));
        }

        $fallback = $today[0]
            ?? $candidates->first(fn (Employee $employee) => (int) $employee->store_id === (int) $user->store_id)
            ?? $candidates->first(fn (Employee $employee) => (bool) $employee->store->is_primary)
            ?? $candidates->first();

        return $pick($fallback);
    }

    /**
     * Data karyawan aktif milik akun ini di semua outlet yang modul absensinya aktif.
     *
     * @return \Illuminate\Support\Collection<int, Employee>
     */
    private function candidateEmployees(User $user)
    {
        $employees = Employee::query()
            ->with('store')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (Employee $employee) => $employee->store && $employee->store->is_active);

        if ($employees->isEmpty()) {
            $this->fail(403, 'not_employee', 'Akun ini belum terdaftar sebagai karyawan aktif. Minta pemilik toko menghubungkan akun Anda di menu Karyawan.');
        }

        $candidates = $employees
            ->filter(fn (Employee $employee) => AttendancePayrollAddOnService::isPurchased($employee->store)
                && AttendanceSettings::toggleEnabled($employee->store))
            ->values();

        if ($candidates->isEmpty()) {
            $this->fail(403, 'module_inactive', 'Fitur absensi belum aktif di toko ini. Hubungi pemilik toko.');
        }

        return $candidates;
    }

    /**
     * Pilih outlet untuk absen masuk berdasarkan posisi GPS karyawan.
     *
     * @param  \Illuminate\Support\Collection<int, Employee>  $candidates
     * @param  array{latitude: float|null, longitude: float|null}  $geo
     */
    private function outletForPosition($candidates, array $geo): Employee
    {
        $withoutGeo = $candidates->first(fn (Employee $e) => ! AttendanceSettings::requireGeolocation($e->store));
        $latitude = $geo['latitude'] ?? null;
        $longitude = $geo['longitude'] ?? null;

        if ($latitude === null || $longitude === null) {
            // Biarkan validasi lokasi biasa yang memberi pesan "aktifkan GPS".
            return $withoutGeo ?? $candidates->first();
        }

        $nearest = $candidates
            ->filter(fn (Employee $e) => AttendanceSettings::requireGeolocation($e->store) && AttendanceSettings::hasOfficeLocation($e->store))
            ->map(fn (Employee $e) => [
                'employee' => $e,
                'distance' => $this->geolocation->distanceMeters(
                    AttendanceSettings::officeLatitude($e->store),
                    AttendanceSettings::officeLongitude($e->store),
                    (float) $latitude,
                    (float) $longitude,
                ),
            ])
            ->filter(fn (array $row) => $row['distance'] <= AttendanceSettings::radiusMeters($row['employee']->store))
            ->sortBy('distance')
            ->first();

        if ($nearest) {
            return $nearest['employee'];
        }

        if ($withoutGeo) {
            return $withoutGeo;
        }

        throw ValidationException::withMessages([
            'location' => 'Anda berada di luar radius semua outlet tempat Anda terdaftar ('
                .$candidates->map(fn (Employee $e) => $e->store->name)->join(', ').').',
        ]);
    }

    /**
     * @return array{bound: bool, is_this_device: bool, name: string|null, message: string|null}
     */
    private function deviceStatus(Request $request, Employee $employee): array
    {
        $deviceId = trim((string) $request->header('X-Device-Id', ''));
        $bound = $this->boundDevice($employee);
        $message = $this->deviceProblem($employee, $bound, $deviceId);

        return [
            'bound' => (bool) $bound,
            'is_this_device' => $message === null,
            'name' => $bound?->device_name,
            'message' => $message,
        ];
    }

    /**
     * Kunci HP berlaku per ORANG (akun), bukan per outlet:
     * - tiap karyawan absen dari HP-nya sendiri; HP pertama yang dipakai terdaftar otomatis
     *   untuk semua outlet tempat ia terdaftar;
     * - satu HP tidak bisa dipakai absen oleh dua orang berbeda (mencegah titip absen).
     */
    private function ensureDevice(Request $request, Employee $employee, Store $store): void
    {
        $deviceId = trim((string) $request->header('X-Device-Id', ''));
        if ($deviceId === '' || strlen($deviceId) > 100) {
            $this->fail(422, 'device_missing', 'Perangkat tidak dikenali. Perbarui aplikasi Absensi.');
        }

        $bound = $this->boundDevice($employee);
        if ($problem = $this->deviceProblem($employee, $bound, $deviceId)) {
            $this->fail(423, $bound ? 'device_mismatch' : 'device_taken', $problem);
        }

        // Daftarkan HP ini ke semua data karyawan milik orang yang sama yang belum punya HP.
        $unbound = $this->siblingEmployees($employee)->filter(fn (Employee $e) => ! $e->device_id);
        if ($unbound->isEmpty()) {
            return;
        }

        $name = $bound?->device_name ?? mb_substr((string) $request->header('X-Device-Name', 'HP Android'), 0, 150);
        foreach ($unbound as $record) {
            $record->forceFill(['device_id' => $deviceId, 'device_name' => $name, 'device_bound_at' => now()])->save();
        }
        if (! $bound) {
            ActivityLogger::log($store, 'attendance.device_bound', "HP {$name} didaftarkan untuk {$employee->name}", $employee, [], $request->user());
        }
    }

    /**
     * Semua data karyawan aktif milik orang yang sama (satu per outlet).
     *
     * @return \Illuminate\Support\Collection<int, Employee>
     */
    private function siblingEmployees(Employee $employee)
    {
        if (! $employee->user_id) {
            return collect([$employee]);
        }

        return Employee::query()
            ->where('user_id', $employee->user_id)
            ->where('is_active', true)
            ->get();
    }

    /** HP yang sudah terdaftar untuk orang ini (di outlet mana pun). */
    private function boundDevice(Employee $employee): ?Employee
    {
        return $employee->device_id
            ? $employee
            : $this->siblingEmployees($employee)->first(fn (Employee $e) => (bool) $e->device_id);
    }

    /** Pesan penolakan bila HP ini tidak boleh dipakai absen oleh karyawan ini; null = boleh. */
    private function deviceProblem(Employee $employee, ?Employee $bound, string $deviceId): ?string
    {
        if ($bound) {
            return $deviceId !== '' && hash_equals($bound->device_id, $deviceId)
                ? null
                : "Akun Anda terdaftar di HP lain ({$bound->device_name}). Minta pemilik toko melepas HP lama di menu Karyawan.";
        }

        if ($deviceId === '') {
            return null;
        }

        $owner = Employee::query()
            ->where('device_id', $deviceId)
            ->where('is_active', true)
            ->when($employee->user_id, fn ($q) => $q->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $employee->user_id)))
            ->where('id', '!=', $employee->id)
            ->first();

        if (! $owner) {
            return null;
        }

        return (int) $owner->store_id === (int) $employee->store_id
            ? "HP ini sudah terdaftar untuk {$owner->name}. Absen harus memakai HP Anda sendiri."
            : 'HP ini sudah terdaftar untuk karyawan lain. Absen harus memakai HP Anda sendiri.';
    }

    /**
     * @return array{0: array{latitude: float|null, longitude: float|null, accuracy: float|null}, 1: \Illuminate\Http\UploadedFile|null}
     */
    private function validateCapture(Request $request): array
    {
        $validated = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'is_mocked' => ['nullable', 'boolean'],
            'photo' => ['nullable', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        if ($request->boolean('is_mocked')) {
            throw ValidationException::withMessages([
                'location' => 'Lokasi palsu (fake GPS) terdeteksi. Matikan aplikasi pemalsu lokasi lalu coba lagi.',
            ]);
        }

        return [[
            'latitude' => isset($validated['latitude']) ? (float) $validated['latitude'] : null,
            'longitude' => isset($validated['longitude']) ? (float) $validated['longitude'] : null,
            'accuracy' => isset($validated['accuracy']) ? (float) $validated['accuracy'] : null,
        ], $request->file('photo')];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $message], $status));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSchedule(WorkSchedule $schedule): array
    {
        return [
            'name' => $schedule->name,
            'work_days' => array_map('intval', $schedule->work_days ?? []),
            'shift_start' => substr((string) $schedule->shift_start, 0, 5),
            'shift_end' => substr((string) $schedule->shift_end, 0, 5),
            'break_minutes' => (int) $schedule->break_minutes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAttendance(Attendance $a): array
    {
        return [
            'id' => $a->id,
            'work_date' => $a->work_date?->toDateString(),
            'clock_in' => $a->clock_in?->format('H:i'),
            'clock_out' => $a->clock_out?->format('H:i'),
            'status' => $a->status,
            'late_minutes' => (int) $a->late_minutes,
            'hours_worked' => $a->hours_worked !== null ? (float) $a->hours_worked : null,
            'overtime_hours' => $a->overtime_hours !== null ? (float) $a->overtime_hours : null,
            'notes' => $a->notes,
        ];
    }
}
