<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Models\Store;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\WorkSchedule;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkScheduleController extends AdminAttendanceController
{
    public function index()
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        $schedules = WorkSchedule::query()
            ->where('store_id', $store->id)
            ->with('employee:id,name,position')
            ->latest()
            ->get()
            ->map(fn (WorkSchedule $schedule) => $this->serialize($schedule))
            ->values();

        return Inertia::render('Attendance/Schedules', [
            'schedules' => $schedules,
            'employees' => $this->employees($store),
        ]);
    }

    public function store(Request $request)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        WorkSchedule::create([
            'store_id' => $store->id,
            ...$this->validateData($request),
        ]);

        return back()->with('success', 'Jadwal kerja disimpan.');
    }

    public function update(Request $request, WorkSchedule $schedule)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($schedule, $store);

        $schedule->update($this->validateData($request));

        return back()->with('success', 'Jadwal kerja diperbarui.');
    }

    public function destroy(WorkSchedule $schedule)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($schedule, $store);

        $schedule->delete();

        return back()->with('success', 'Jadwal kerja dihapus.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'employee_id' => ['required', 'integer', $this->storeEmployeeRule()],
            'name' => 'required|string|max:100',
            'work_days' => 'required|array|min:1',
            'work_days.*' => 'integer|between:1,7',
            'shift_start' => 'required|date_format:H:i',
            'shift_end' => 'required|date_format:H:i',
            'break_minutes' => 'integer|min:0|max:240',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'is_active' => 'boolean',
        ]);
    }

    private function ensureOwned(WorkSchedule $schedule, Store $store): void
    {
        abort_if($schedule->store_id !== $store->id, 404);
    }

    private function employees(Store $store): array
    {
        return Employee::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->get(['id', 'name', 'position'])
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'position' => $employee->position,
            ])
            ->all();
    }

    private function serialize(WorkSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'employee_id' => $schedule->employee_id,
            'employee_name' => $schedule->employee?->name,
            'name' => $schedule->name,
            'work_days' => $schedule->work_days ?? [],
            'shift_start' => substr((string) $schedule->shift_start, 0, 5),
            'shift_end' => substr((string) $schedule->shift_end, 0, 5),
            'break_minutes' => $schedule->break_minutes,
            'valid_from' => $schedule->valid_from?->toDateString(),
            'valid_to' => $schedule->valid_to?->toDateString(),
            'is_active' => (bool) $schedule->is_active,
        ];
    }
}
