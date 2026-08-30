<?php

use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\LeaveRequest;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeAttendanceStore(): Store
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'starter'],
        ['name' => 'Starter', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true]
    );

    return Store::query()->create([
        'name' => 'Toko Absen',
        'slug' => 'absen-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'timezone' => 'Asia/Makassar',
    ]);
}

function makeScheduledEmployee(Store $store, CarbonImmutable $date): Employee
{
    $employee = Employee::query()->create([
        'store_id' => $store->id,
        'name' => 'Karyawan Uji',
        'is_active' => true,
    ]);

    WorkSchedule::query()->create([
        'store_id' => $store->id,
        'employee_id' => $employee->id,
        'name' => 'Jadwal Uji',
        'work_days' => [$date->dayOfWeekIso],
        'shift_start' => '08:00',
        'shift_end' => '17:00',
        'break_minutes' => 60,
        'valid_from' => $date->subDays(7),
        'valid_to' => $date->addDays(7),
        'is_active' => true,
    ]);

    return $employee;
}

it('menandai absent karyawan berjadwal yang tidak hadir', function () {
    $store = makeAttendanceStore();
    $date = CarbonImmutable::parse('2026-08-10');
    makeScheduledEmployee($store, $date);

    $marked = app(AttendanceService::class)->markAbsent($store, $date);

    expect($marked)->toBe(1);

    $attendance = Attendance::query()->where('store_id', $store->id)->first();
    expect($attendance->status)->toBe(Attendance::STATUS_ABSENT)
        ->and($attendance->source)->toBe(Attendance::SOURCE_IMPORTED)
        ->and($attendance->work_date->toDateString())->toBe($date->toDateString());
});

it('idempoten: tidak membuat absent ganda', function () {
    $store = makeAttendanceStore();
    $date = CarbonImmutable::parse('2026-08-10');
    $employee = makeScheduledEmployee($store, $date);

    Attendance::query()->create([
        'store_id' => $store->id,
        'employee_id' => $employee->id,
        'work_date' => $date->toDateString(),
        'status' => Attendance::STATUS_PRESENT,
        'source' => Attendance::SOURCE_MANUAL,
    ]);

    $marked = app(AttendanceService::class)->markAbsent($store, $date);

    expect($marked)->toBe(0)
        ->and(Attendance::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

it('melewati karyawan yang sedang cuti disetujui', function () {
    $store = makeAttendanceStore();
    $date = CarbonImmutable::parse('2026-08-10');
    $employee = makeScheduledEmployee($store, $date);

    LeaveRequest::query()->create([
        'store_id' => $store->id,
        'employee_id' => $employee->id,
        'leave_type' => 'cuti',
        'start_date' => $date->toDateString(),
        'end_date' => $date->toDateString(),
        'status' => 'approved',
    ]);

    $marked = app(AttendanceService::class)->markAbsent($store, $date);

    expect($marked)->toBe(0);
});

it('tidak menandai absent di luar hari jadwal', function () {
    $store = makeAttendanceStore();
    $date = CarbonImmutable::parse('2026-08-10'); // Senin
    $employee = makeScheduledEmployee($store, $date);

    $sunday = CarbonImmutable::parse('2026-08-16');

    $marked = app(AttendanceService::class)->markAbsent($store, $sunday);

    expect($marked)->toBe(0)
        ->and(Attendance::query()->where('employee_id', $employee->id)->count())->toBe(0);
});
