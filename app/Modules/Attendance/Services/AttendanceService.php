<?php

namespace App\Modules\Attendance\Services;

use App\Models\CashShift;
use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\LeaveRequest;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Support\AttendanceSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inti logika absensi: clock in/out, validasi geolokasi & selfie,
 * perhitungan keterlambatan/jam kerja/lembur, dan agregasi untuk payroll.
 *
 * Semua penulisan kehadiran dibungkus transaksi DB + lock baris attendance
 * untuk mencegah double clock-in/out pada hari yang sama.
 */
class AttendanceService
{
    public function __construct(
        private readonly GeolocationService $geo,
        private readonly AttendancePhotoService $photos,
    ) {}

    /**
     * Temukan profil karyawan dari akun user pada toko aktif.
     */
    public function resolveEmployee(User $user, Store $store): Employee
    {
        return Employee::query()->firstOrCreate(
            [
                'store_id' => $store->id,
                'user_id' => $user->id,
            ],
            [
                'name' => $user->name,
                'is_active' => true,
            ],
        );
    }

    /**
     * Resolusi karyawan untuk sesi absensi. Prioritaskan kasir aktif (login PIN)
     * dari session; fallback ke profil user akun (owner/admin absen atas nama sendiri).
     */
    public function resolveActiveEmployee(User $user, Store $store): Employee
    {
        $activeId = session('active_cashier.employee_id');

        if ($activeId) {
            $employee = Employee::query()
                ->where('id', $activeId)
                ->where('store_id', $store->id)
                ->where('is_active', true)
                ->first();

            if ($employee) {
                return $employee;
            }
        }

        return $this->resolveEmployee($user, $store);
    }

    /**
     * Catat jam masuk. Wajib lolos geolokasi & selfie sesuai kebijakan toko.
     *
     * @param  array{latitude?: float|null, longitude?: float|null, accuracy?: float|null}  $geo
     */
    public function clockIn(
        Store $store,
        Employee $employee,
        array $geo = [],
        ?UploadedFile $photo = null,
        ?User $actor = null,
    ): Attendance {
        $this->assertGeolocation($store, $geo);
        $this->assertSelfie($store, $photo);

        return DB::transaction(function () use ($store, $employee, $geo, $photo, $actor) {
            $workDate = $this->today($store)->toDateString();

            /** @var Attendance|null $locked */
            $locked = Attendance::query()
                ->where('employee_id', $employee->id)
                ->where('work_date', $workDate)
                ->lockForUpdate()
                ->first();

            if ($locked && $locked->clock_in) {
                throw ValidationException::withMessages([
                    'clock_in' => 'Anda sudah melakukan check-in hari ini.',
                ]);
            }

            $attendance = $locked ?? new Attendance([
                'store_id' => $store->id,
                'employee_id' => $employee->id,
                'work_date' => $workDate,
                'source' => Attendance::SOURCE_MANUAL,
            ]);

            $attendance->forceFill([
                'clock_in' => $this->today($store),
                'clock_in_latitude' => $geo['latitude'] ?? null,
                'clock_in_longitude' => $geo['longitude'] ?? null,
                'clock_in_accuracy' => $geo['accuracy'] ?? null,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ])->save();

            if ($photo) {
                $this->photos->store($photo, $store, $employee, $attendance, 'in');
            }

            $this->applySchedule($attendance);

            return $attendance;
        });
    }

    /**
     * Catat jam pulang.
     *
     * @param  array{latitude?: float|null, longitude?: float|null, accuracy?: float|null}  $geo
     */
    public function clockOut(
        Store $store,
        Attendance $attendance,
        array $geo = [],
        ?UploadedFile $photo = null,
        ?User $actor = null,
    ): Attendance {
        $this->assertGeolocation($store, $geo);
        $this->assertSelfie($store, $photo);

        return DB::transaction(function () use ($store, $attendance, $geo, $photo, $actor) {
            /** @var Attendance $locked */
            $locked = Attendance::query()->lockForUpdate()->findOrFail($attendance->id);

            if (! $locked->clock_in) {
                throw ValidationException::withMessages([
                    'clock_out' => 'Tidak bisa check-out sebelum check-in.',
                ]);
            }

            if ($locked->clock_out) {
                throw ValidationException::withMessages([
                    'clock_out' => 'Anda sudah melakukan check-out hari ini.',
                ]);
            }

            $locked->forceFill([
                'clock_out' => $this->today($store),
                'clock_out_latitude' => $geo['latitude'] ?? null,
                'clock_out_longitude' => $geo['longitude'] ?? null,
                'clock_out_accuracy' => $geo['accuracy'] ?? null,
                'updated_by' => $actor?->id,
            ])->save();

            if ($photo) {
                $this->photos->store($photo, $store, $locked->employee, $locked, 'out');
            }

            $this->applySchedule($locked);

            return $locked;
        });
    }

    /**
     * Hitung ulang field turunan (telat, pulang cepat, jam kerja, lembur) dari jadwal.
     * Aman dipanggil berulang (idempoten).
     */
    public function applySchedule(Attendance $attendance): void
    {
        $schedule = $this->findScheduleFor(
            $attendance->employee_id,
            $attendance->work_date,
        );

        if (! $schedule) {
            $attendance->forceFill([
                'status' => Attendance::STATUS_PRESENT,
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'hours_worked' => null,
                'overtime_hours' => null,
            ])->save();

            return;
        }

        $shiftStart = $this->minutesSinceMidnight($schedule->shift_start);
        $shiftEnd = $this->minutesSinceMidnight($schedule->shift_end);
        $grace = AttendanceSettings::graceMinutes($attendance->store);

        $late = 0;
        $early = 0;
        $hoursWorked = null;
        $overtime = null;

        if ($attendance->clock_in) {
            $clockIn = $this->minutesSinceMidnight($attendance->clock_in);
            $late = max(0, $clockIn - ($shiftStart + $grace));

            if ($attendance->clock_out) {
                $clockOut = $this->minutesSinceMidnight($attendance->clock_out);
                $early = max(0, $shiftEnd - $clockOut);
                $workedMinutes = max(0, $clockOut - $clockIn - $schedule->break_minutes);
                $hoursWorked = round($workedMinutes / 60, 2);
                $overtime = round(max(0, $clockOut - $shiftEnd) / 60, 2);
            }
        }

        $status = $late > 0 ? Attendance::STATUS_LATE : Attendance::STATUS_PRESENT;

        $attendance->forceFill([
            'schedule_id' => $schedule->id,
            'status' => $status,
            'late_minutes' => $late,
            'early_leave_minutes' => $early,
            'hours_worked' => $hoursWorked,
            'overtime_hours' => $overtime,
        ])->save();
    }

    /**
     * Agregasi kehadiran untuk periode gaji. Kontrak data ke PayrollService.
     *
     * @return array{
     *     work_days:int, present_days:int, late_days:int, absent_days:int,
     *     leave_days:int, total_hours:float, total_overtime:float, total_late_min:int
     * }
     */
    public function aggregate(
        Employee $employee,
        CarbonInterface $from,
        CarbonInterface $to,
    ): array {
        $attendances = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $overtimeApproved = AttendanceSettings::overtimeRequiresApproval($employee->store)
            ? OvertimeRequest::query()
                ->where('employee_id', $employee->id)
                ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
                ->where('status', 'approved')
                ->sum('hours')
            : (float) $attendances->sum('overtime_hours');

        $overtime = AttendanceSettings::overtimeRequiresApproval($employee->store)
            ? (float) $overtimeApproved
            : (float) $attendances->sum('overtime_hours');

        return [
            'work_days' => $attendances->count(),
            'present_days' => $attendances
                ->whereIn('status', [Attendance::STATUS_PRESENT, Attendance::STATUS_LATE])->count(),
            'late_days' => $attendances->where('status', Attendance::STATUS_LATE)->count(),
            'absent_days' => $attendances->where('status', Attendance::STATUS_ABSENT)->count(),
            'leave_days' => $attendances
                ->whereIn('status', [Attendance::STATUS_LEAVE, Attendance::STATUS_SICK])->count(),
            'total_hours' => round((float) $attendances->sum('hours_worked'), 2),
            'total_overtime' => round($overtime, 2),
            'total_late_min' => (int) $attendances->sum('late_minutes'),
        ];
    }

    /**
     * Konversi shift kasir (cash_shifts) menjadi absensi harian secara idempoten.
     * Work_date mengikuti tanggal mulai shift pada timezone toko. Data manual
     * tidak ditimpa (prioritas manual > otomatis).
     *
     * @return int jumlah attendance yang diproses
     */
    public function generateFromCashShifts(Store $store, CarbonInterface $date): int
    {
        $tz = $store->timezone ?: 'UTC';
        $dayStart = CarbonImmutable::parse($date->toDateString().' 00:00:00', $tz);
        $dayEnd = $dayStart->addDay();

        $shifts = CashShift::query()
            ->where('store_id', $store->id)
            ->whereBetween('opened_at', [$dayStart, $dayEnd])
            ->get();

        $processed = 0;

        foreach ($shifts as $shift) {
            $employee = null;

            if ($shift->employee_id) {
                $employee = Employee::query()
                    ->where('store_id', $store->id)
                    ->where('id', $shift->employee_id)
                    ->first();
            }

            if (! $employee) {
                $employee = Employee::query()
                    ->where('store_id', $store->id)
                    ->where('user_id', $shift->user_id)
                    ->first();
            }

            if (! $employee) {
                continue;
            }

            $workDate = $shift->opened_at->setTimezone($tz)->toDateString();

            $attendance = Attendance::firstOrNew([
                'employee_id' => $employee->id,
                'work_date' => $workDate,
            ]);

            if ($attendance->exists && $attendance->source === Attendance::SOURCE_MANUAL) {
                continue;
            }

            $attendance->forceFill([
                'store_id' => $store->id,
                'cash_shift_id' => $shift->id,
                'source' => Attendance::SOURCE_CASH_SHIFT,
            ]);

            if (! $attendance->clock_in) {
                $attendance->clock_in = $shift->opened_at;
            }

            if ($shift->closed_at && ! $attendance->clock_out) {
                $attendance->clock_out = $shift->closed_at;
            }

            $attendance->save();
            $this->applySchedule($attendance);

            $processed++;
        }

        return $processed;
    }

    /**
     * Tandai karyawan yang berjadwal tapi tidak hadir sebagai absent (idempoten).
     * Default menandai hari kemarin menurut timezone toko agar jam kerja sudah lewat.
     *
     * @return int jumlah attendance absent yang dibuat
     */
    public function markAbsent(Store $store, ?CarbonInterface $date = null): int
    {
        $tz = $store->timezone ?: 'UTC';
        $workDate = $date
            ? CarbonImmutable::parse($date->toDateString(), $tz)
            : CarbonImmutable::now($tz)->subDay();

        $dateString = $workDate->toDateString();
        $marked = 0;

        $employees = Employee::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->get();

        foreach ($employees as $employee) {
            $schedule = $this->findScheduleFor($employee->id, $workDate);

            if (! $schedule) {
                continue;
            }

            if (Attendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $dateString)
                ->exists()) {
                continue;
            }

            if (LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $dateString)
                ->whereDate('end_date', '>=', $dateString)
                ->exists()) {
                continue;
            }

            Attendance::create([
                'store_id' => $store->id,
                'employee_id' => $employee->id,
                'work_date' => $dateString,
                'schedule_id' => $schedule->id,
                'status' => Attendance::STATUS_ABSENT,
                'source' => Attendance::SOURCE_IMPORTED,
            ]);

            $marked++;
        }

        return $marked;
    }

    /**
     * Cari jadwal aktif untuk karyawan pada tanggal tertentu.
     */
    public function findScheduleFor(int $employeeId, CarbonInterface $workDate): ?WorkSchedule
    {
        $dayOfWeek = $workDate->dayOfWeek; // 0 = Minggu .. 6 = Sabtu
        $iso = $dayOfWeek === 0 ? 7 : $dayOfWeek; // 1 = Senin .. 7 = Minggu

        return WorkSchedule::query()
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->where(function ($query) use ($workDate) {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', $workDate->toDateString());
            })
            ->where(function ($query) use ($workDate) {
                $query->whereNull('valid_to')->orWhere('valid_to', '>=', $workDate->toDateString());
            })
            ->get()
            ->first(fn (WorkSchedule $schedule) => in_array($iso, $schedule->work_days, true));
    }

    private function assertGeolocation(Store $store, array $geo): void
    {
        if (! AttendanceSettings::requireGeolocation($store)) {
            return;
        }

        if (! AttendanceSettings::hasOfficeLocation($store)) {
            throw ValidationException::withMessages([
                'location' => 'Lokasi kantor belum diatur oleh admin.',
            ]);
        }

        $latitude = $geo['latitude'] ?? null;
        $longitude = $geo['longitude'] ?? null;

        if ($latitude === null || $longitude === null) {
            throw ValidationException::withMessages([
                'location' => 'Lokasi Anda tidak dapat ditentukan. Aktifkan GPS dan coba lagi.',
            ]);
        }

        if (! $this->geo->isWithinRadius($store, (float) $latitude, (float) $longitude)) {
            throw ValidationException::withMessages([
                'location' => 'Anda berada di luar radius lokasi kantor.',
            ]);
        }
    }

    private function assertSelfie(Store $store, ?UploadedFile $photo): void
    {
        if (AttendanceSettings::requireSelfie($store) && ! $photo) {
            throw ValidationException::withMessages([
                'photo' => 'Foto selfie wajib diunggah sebagai bukti kehadiran.',
            ]);
        }
    }

    private function today(Store $store): CarbonImmutable
    {
        return CarbonImmutable::now($store->timezone ?: 'UTC');
    }

    private function minutesSinceMidnight(CarbonInterface|string $time): int
    {
        $carbon = $time instanceof CarbonInterface ? $time : CarbonImmutable::parse($time);

        return $carbon->hour * 60 + $carbon->minute;
    }
}
