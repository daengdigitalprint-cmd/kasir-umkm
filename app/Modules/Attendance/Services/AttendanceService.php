<?php

namespace App\Modules\Attendance\Services;

use App\Models\CashShift;
use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Models\LeaveRequest;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Support\AttendanceSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
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

        return $this->writeClockIn($store, $employee, $geo, $photo, $actor);
    }

    /**
     * Absen yang dicatat admin/pemilik atas nama karyawan (mis. dari aplikasi mobile).
     * Tanpa geolokasi/selfie karena dilakukan atasan yang berwenang, tetapi tetap memakai
     * zona waktu toko, perhitungan jadwal, dan penguncian yang sama dengan absen web.
     */
    public function recordByAdmin(Store $store, Employee $employee, string $type, User $actor): Attendance
    {
        $this->assertEmployeeCanAttend($store, $employee);

        // Pesan dari sudut pandang admin yang mencatat, bukan karyawan sendiri.
        $attendance = $type === 'in'
            ? Attendance::query()
                ->where('store_id', $store->id)
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $this->today($store)->toDateString())
                ->first()
            : $this->openAttendanceFor($store, $employee);

        if ($type === 'in') {
            if ($attendance?->clock_in) {
                throw ValidationException::withMessages(['clock_in' => 'Karyawan sudah clock-in hari ini.']);
            }

            return $this->writeClockIn($store, $employee, [], null, $actor, Attendance::SOURCE_DEVICE);
        }

        if (! $attendance?->clock_in) {
            throw ValidationException::withMessages(['clock_out' => 'Karyawan belum clock-in hari ini.']);
        }

        if ($attendance->clock_out) {
            throw ValidationException::withMessages(['clock_out' => 'Karyawan sudah clock-out hari ini.']);
        }

        return $this->writeClockOut($store, $attendance, [], null, $actor);
    }

    /**
     * @param  array{latitude?: float|null, longitude?: float|null, accuracy?: float|null}  $geo
     */
    private function writeClockIn(
        Store $store,
        Employee $employee,
        array $geo,
        ?UploadedFile $photo,
        ?User $actor,
        string $source = Attendance::SOURCE_MANUAL,
    ): Attendance {
        $this->assertEmployeeCanAttend($store, $employee);

        try {
            return DB::transaction(function () use ($store, $employee, $geo, $photo, $actor, $source) {
                $workDate = $this->today($store)->toDateString();

                /** @var Attendance|null $locked */
                $locked = Attendance::query()
                    ->where('employee_id', $employee->id)
                    ->whereDate('work_date', $workDate)
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
                    'source' => $source,
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
        } catch (UniqueConstraintViolationException) {
            // Dua check-in bersamaan untuk karyawan & tanggal yang sama: indeks unik yang menang.
            throw ValidationException::withMessages([
                'clock_in' => 'Anda sudah melakukan check-in hari ini.',
            ]);
        }
    }

    private function assertEmployeeCanAttend(Store $store, Employee $employee): void
    {
        if ((int) $employee->store_id !== (int) $store->id || ! $employee->is_active) {
            throw ValidationException::withMessages([
                'employee' => 'Karyawan tidak aktif atau bukan bagian dari toko ini.',
            ]);
        }
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

        return $this->writeClockOut($store, $attendance, $geo, $photo, $actor);
    }

    /**
     * @param  array{latitude?: float|null, longitude?: float|null, accuracy?: float|null}  $geo
     */
    private function writeClockOut(
        Store $store,
        Attendance $attendance,
        array $geo,
        ?UploadedFile $photo,
        ?User $actor,
    ): Attendance {
        abort_if((int) $attendance->store_id !== (int) $store->id, 404);

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
            (int) $attendance->store_id,
        );

        if (! $attendance->clock_in) {
            // Tidak ada jam masuk: pertahankan status (alpa/cuti/sakit/libur), nolkan turunan.
            $attendance->forceFill([
                'schedule_id' => $schedule?->id ?? $attendance->schedule_id,
                'status' => in_array($attendance->status, [null, '', Attendance::STATUS_PRESENT, Attendance::STATUS_LATE], true)
                    ? Attendance::STATUS_ABSENT
                    : $attendance->status,
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'hours_worked' => null,
                'overtime_hours' => null,
            ])->save();

            return;
        }

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

        // Shift malam (mis. 22:00–06:00): jam pulang ada di hari berikutnya.
        if ($shiftEnd <= $shiftStart) {
            $shiftEnd += 24 * 60;
        }

        if ($attendance->clock_in) {
            $clockIn = $this->minutesSinceMidnight($attendance->clock_in);
            $late = max(0, $clockIn - ($shiftStart + $grace));

            if ($attendance->clock_out) {
                // Pakai selisih waktu sebenarnya agar check-out lewat tengah malam tetap benar.
                $clockOut = $clockIn + max(0, (int) round(
                    ($attendance->clock_out->getTimestamp() - $attendance->clock_in->getTimestamp()) / 60
                ));
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
            ->where('store_id', $employee->store_id)
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $overtimeApproved = AttendanceSettings::overtimeRequiresApproval($employee->store)
            ? OvertimeRequest::query()
                ->where('store_id', $employee->store_id)
                ->where('employee_id', $employee->id)
                ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
                ->where('status', 'approved')
                ->sum('hours')
            : (float) $attendances->sum('overtime_hours');

        $overtime = AttendanceSettings::overtimeRequiresApproval($employee->store)
            ? (float) $overtimeApproved
            : (float) $attendances->sum('overtime_hours');

        // Lembur per tanggal: tarif lembur dihitung per hari (jam pertama vs jam berikutnya).
        $overtimeByDay = AttendanceSettings::overtimeRequiresApproval($employee->store)
            ? OvertimeRequest::query()
                ->where('store_id', $employee->store_id)
                ->where('employee_id', $employee->id)
                ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
                ->where('status', 'approved')
                ->get()
                ->groupBy(fn (OvertimeRequest $o) => $o->work_date->toDateString())
                ->map(fn ($rows) => round((float) $rows->sum('hours'), 2))
            : $attendances
                ->filter(fn (Attendance $a) => (float) $a->overtime_hours > 0)
                ->groupBy(fn (Attendance $a) => $a->work_date->toDateString())
                ->map(fn ($rows) => round((float) $rows->sum('overtime_hours'), 2));

        $unpaidLeaveIds = LeaveRequest::query()
            ->where('store_id', $employee->store_id)
            ->where('employee_id', $employee->id)
            ->where('leave_type', 'unpaid')
            ->pluck('id')
            ->flip();

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
            'unpaid_leave_days' => $attendances
                ->filter(fn (Attendance $a) => $a->leave_request_id && $unpaidLeaveIds->has($a->leave_request_id))
                ->count(),
            'overtime_by_day' => $overtimeByDay->all(),
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
            // Kolom timestamp disimpan dalam zona waktu aplikasi (APP_TIMEZONE), bukan selalu UTC.
            ->whereBetween('opened_at', [
                $dayStart->setTimezone(config('app.timezone')),
                $dayEnd->setTimezone(config('app.timezone')),
            ])
            ->get();

        // Batch-load employees to avoid N+1 queries
        $employeeIds = $shifts->pluck('employee_id')->filter()->unique()->values();
        $userIds = $shifts->pluck('user_id')->filter()->unique()->values();

        $employeeById = Employee::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $employeeIds)
            ->get()
            ->keyBy('id');

        $employeeByUserId = Employee::query()
            ->where('store_id', $store->id)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $processed = 0;

        foreach ($shifts as $shift) {
            $employee = null;

            if ($shift->employee_id) {
                $employee = $employeeById->get($shift->employee_id);
            }

            if (! $employee) {
                $employee = $employeeByUserId->get($shift->user_id);
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

            // Konvensi kolom clock_in/clock_out = jam dinding toko (sama dengan absen web).
            if (! $attendance->clock_in) {
                $attendance->clock_in = $this->wallClock($shift->opened_at, $tz);
            }

            if ($shift->closed_at && ! $attendance->clock_out) {
                $attendance->clock_out = $this->wallClock($shift->closed_at, $tz);
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

        if (Holiday::query()->where('store_id', $store->id)->whereDate('date', $dateString)->exists()) {
            return 0;
        }

        $employees = Employee::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->get();

        $employeeIds = $employees->pluck('id')->values();

        // Batch-load existing attendance and leave requests for the date
        $existingAttendanceEmployeeIds = Attendance::query()
            ->where('store_id', $store->id)
            ->whereDate('work_date', $dateString)
            ->whereIn('employee_id', $employeeIds)
            ->pluck('employee_id')
            ->flip();

        $leaveEmployeeIds = LeaveRequest::query()
            ->where('store_id', $store->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $dateString)
            ->whereDate('end_date', '>=', $dateString)
            ->whereIn('employee_id', $employeeIds)
            ->pluck('employee_id')
            ->flip();

        // Batch-load all schedules for these employees
        $allSchedules = WorkSchedule::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->whereIn('employee_id', $employeeIds)
            ->get()
            ->groupBy('employee_id');

        foreach ($employees as $employee) {
            $schedule = null;
            $dayOfWeek = $workDate->dayOfWeek;
            $iso = $dayOfWeek === 0 ? 7 : $dayOfWeek;

            $schedules = $allSchedules->get($employee->id, collect());
            foreach ($schedules as $s) {
                $validFromOk = ! $s->valid_from || $s->valid_from <= $dateString;
                $validToOk = ! $s->valid_to || $s->valid_to >= $dateString;
                if ($validFromOk && $validToOk && in_array($iso, $s->work_days, true)) {
                    $schedule = $s;
                    break;
                }
            }

            if (! $schedule) {
                continue;
            }

            if ($existingAttendanceEmployeeIds->has($employee->id)) {
                continue;
            }

            if ($leaveEmployeeIds->has($employee->id)) {
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
    public function findScheduleFor(int $employeeId, CarbonInterface $workDate, ?int $storeId = null): ?WorkSchedule
    {
        $dayOfWeek = $workDate->dayOfWeek; // 0 = Minggu .. 6 = Sabtu
        $iso = $dayOfWeek === 0 ? 7 : $dayOfWeek; // 1 = Senin .. 7 = Minggu

        return WorkSchedule::query()
            ->where('employee_id', $employeeId)
            ->when($storeId !== null, fn ($query) => $query->where('store_id', $storeId))
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

        // Titik GPS dengan akurasi sangat rendah (mis. ±2 km dari jaringan seluler) bisa saja
        // "jatuh" di dalam radius padahal orangnya jauh. Tolak bila akurasi lebih buruk dari
        // radius kantor (minimal 150 m agar GPS dalam ruangan tetap bisa dipakai).
        $accuracy = $geo['accuracy'] ?? null;
        $maxAccuracy = max(150, AttendanceSettings::radiusMeters($store));
        if ($accuracy !== null && (float) $accuracy > $maxAccuracy) {
            throw ValidationException::withMessages([
                'location' => 'Akurasi lokasi terlalu rendah (±'.round((float) $accuracy).' m). Aktifkan GPS presisi tinggi lalu coba lagi.',
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

    /**
     * Absen yang masih terbuka (sudah masuk, belum pulang) untuk check-out.
     * Mendukung shift malam: absen kemarin yang belum ditutup masih bisa di-check-out
     * selama masih dalam 18 jam sejak jam masuk.
     */
    public function openAttendanceFor(Store $store, Employee $employee): ?Attendance
    {
        $now = $this->today($store);

        $open = Attendance::query()
            ->where('store_id', $store->id)
            ->where('employee_id', $employee->id)
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->whereDate('work_date', '>=', $now->subDay()->toDateString())
            ->orderByDesc('work_date')
            ->first();

        if ($open && $open->clock_in->copy()->addHours(18)->format('Y-m-d H:i:s') >= $now->format('Y-m-d H:i:s')) {
            return $open;
        }

        return Attendance::query()
            ->where('store_id', $store->id)
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $now->toDateString())
            ->first();
    }

    /**
     * Sinkronkan baris absensi dengan status pengajuan cuti.
     * Disetujui → tiap hari kerja dalam rentang dicatat 'leave'/'sick' (kecuali hari libur
     * atau hari yang sudah ada absen masuk). Ditolak/dihapus → baris buatan cuti dihapus.
     */
    public function syncLeave(LeaveRequest $leave): void
    {
        DB::transaction(function () use ($leave) {
            Attendance::query()
                ->where('leave_request_id', $leave->id)
                ->whereNull('clock_in')
                ->delete();

            if ($leave->status !== 'approved' || ! $leave->exists) {
                return;
            }

            $status = $leave->leave_type === 'sick' ? Attendance::STATUS_SICK : Attendance::STATUS_LEAVE;
            $from = CarbonImmutable::parse($leave->start_date->toDateString());
            $to = CarbonImmutable::parse($leave->end_date->toDateString());
            $holidays = Holiday::datesBetween($leave->store_id, $from, $to);
            $hasSchedule = WorkSchedule::query()
                ->where('store_id', $leave->store_id)
                ->where('employee_id', $leave->employee_id)
                ->where('is_active', true)
                ->exists();

            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                $date = $day->toDateString();

                if (isset($holidays[$date])) {
                    continue;
                }

                $schedule = $this->findScheduleFor($leave->employee_id, $day, $leave->store_id);
                if ($hasSchedule && ! $schedule) {
                    continue; // bukan hari kerja karyawan ini
                }

                $existing = Attendance::query()
                    ->where('employee_id', $leave->employee_id)
                    ->whereDate('work_date', $date)
                    ->first();

                if ($existing?->clock_in) {
                    continue; // karyawan tetap masuk kerja
                }

                $row = $existing ?? new Attendance([
                    'store_id' => $leave->store_id,
                    'employee_id' => $leave->employee_id,
                    'work_date' => $date,
                ]);

                $row->forceFill([
                    'schedule_id' => $schedule?->id,
                    'status' => $status,
                    'source' => Attendance::SOURCE_IMPORTED,
                    'leave_request_id' => $leave->id,
                    'late_minutes' => 0,
                    'early_leave_minutes' => 0,
                ])->save();
            }
        });
    }

    /**
     * Ubah waktu UTC dari database menjadi jam dinding toko (tanpa offset),
     * sesuai konvensi kolom clock_in/clock_out.
     */
    private function wallClock(CarbonInterface $stored, string $tz): CarbonImmutable
    {
        return CarbonImmutable::parse($stored->copy()->setTimezone($tz)->format('Y-m-d H:i:s'), config('app.timezone'));
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
