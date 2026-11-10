<?php

namespace App\Modules\Attendance\Services;

use App\Models\Store;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\LeaveRequest;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Support\AttendanceSettings;
use App\Modules\Payroll\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Ringkasan SDM lintas outlet untuk Dashboard Owner:
 * kehadiran hari ini, pengajuan yang menunggu, dan status gaji periode terakhir.
 */
class HrOverviewService
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /**
     * @param  Collection<int, Store>  $stores
     * @return array{outlets: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function forStores(Collection $stores): array
    {
        $outlets = $stores
            ->filter(fn (Store $store) => AttendanceSettings::enabled($store))
            ->map(fn (Store $store) => $this->forStore($store))
            ->values();

        $sum = fn (string $key) => (int) $outlets->sum(fn (array $o) => $o['today'][$key] ?? $o[$key] ?? 0);

        return [
            'outlets' => $outlets->all(),
            'totals' => [
                'employees' => (int) $outlets->sum('employees'),
                'present' => $sum('present'),
                'late' => $sum('late'),
                'on_leave' => $sum('on_leave'),
                'not_yet' => $sum('not_yet'),
                'pending_leave' => (int) $outlets->sum('pending_leave'),
                'pending_overtime' => (int) $outlets->sum('pending_overtime'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function forStore(Store $store): array
    {
        $today = CarbonImmutable::now($store->timezone ?: 'UTC')->startOfDay();
        $employees = Employee::query()->where('store_id', $store->id)->where('is_active', true)->get(['id', 'name']);

        $attendances = Attendance::query()
            ->where('store_id', $store->id)
            ->whereDate('work_date', $today->toDateString())
            ->get(['employee_id', 'status', 'clock_in'])
            ->keyBy('employee_id');

        $onLeaveIds = LeaveRequest::query()
            ->where('store_id', $store->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $today->toDateString())
            ->whereDate('end_date', '>=', $today->toDateString())
            ->pluck('employee_id')
            ->flip();

        $present = 0;
        $late = 0;
        $onLeave = 0;
        $notYet = [];
        foreach ($employees as $employee) {
            $row = $attendances->get($employee->id);
            if ($row?->clock_in) {
                $present++;
                if ($row->status === Attendance::STATUS_LATE) {
                    $late++;
                }
            } elseif ($onLeaveIds->has($employee->id) || in_array($row?->status, [Attendance::STATUS_LEAVE, Attendance::STATUS_SICK], true)) {
                $onLeave++;
            } elseif ($this->attendance->findScheduleFor($employee->id, $today, $store->id)) {
                // Hanya yang memang terjadwal kerja hari ini dihitung "belum absen".
                $notYet[] = $employee->name;
            }
        }

        $period = PayrollPeriod::query()
            ->where('store_id', $store->id)
            ->orderByDesc('period_end')
            ->withSum('runs', 'net_salary')
            ->first();

        return [
            'id' => $store->id,
            'name' => $store->name,
            'employees' => $employees->count(),
            'today' => [
                'present' => $present,
                'late' => $late,
                'on_leave' => $onLeave,
                'not_yet' => count($notYet),
                'not_yet_names' => array_slice($notYet, 0, 5),
            ],
            'pending_leave' => LeaveRequest::query()->where('store_id', $store->id)->where('status', 'pending')->count(),
            'pending_overtime' => OvertimeRequest::query()->where('store_id', $store->id)->where('status', 'pending')->count(),
            'payroll' => $period ? [
                'name' => $period->name,
                'status' => $period->status,
                'total_net' => round((float) ($period->runs_sum_net_salary ?? 0), 2),
            ] : null,
        ];
    }
}
