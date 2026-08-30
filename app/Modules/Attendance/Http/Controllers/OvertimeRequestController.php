<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Models\Store;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\OvertimeRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OvertimeRequestController extends AdminAttendanceController
{
    public function index()
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        $overtimes = OvertimeRequest::query()
            ->where('store_id', $store->id)
            ->with('employee:id,name,position')
            ->latest()
            ->get()
            ->map(fn (OvertimeRequest $overtime) => $this->serialize($overtime))
            ->values();

        return Inertia::render('Attendance/Overtimes', [
            'overtimes' => $overtimes,
            'employees' => $this->employees($store),
        ]);
    }

    public function store(Request $request)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        OvertimeRequest::create([
            'store_id' => $store->id,
            ...$this->validateData($request),
        ]);

        return back()->with('success', 'Pengajuan lembur disimpan.');
    }

    public function update(Request $request, OvertimeRequest $overtime)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($overtime, $store);

        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);

        $overtime->status = $validated['status'];
        $overtime->approved_by = $validated['status'] === 'approved' ? auth()->id() : null;
        $overtime->approved_at = $validated['status'] === 'approved' ? now() : null;
        $overtime->save();

        return back()->with('success', $validated['status'] === 'approved'
            ? 'Lembur disetujui.'
            : 'Lembur ditolak.');
    }

    public function destroy(OvertimeRequest $overtime)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($overtime, $store);

        $overtime->delete();

        return back()->with('success', 'Pengajuan lembur dihapus.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'work_date' => 'required|date',
            'hours' => 'required|numeric|min:0.5|max:24',
            'reason' => 'nullable|string|max:1000',
        ]);
    }

    private function ensureOwned(OvertimeRequest $overtime, Store $store): void
    {
        abort_if($overtime->store_id !== $store->id, 404);
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

    private function serialize(OvertimeRequest $overtime): array
    {
        return [
            'id' => $overtime->id,
            'employee_id' => $overtime->employee_id,
            'employee_name' => $overtime->employee?->name,
            'work_date' => $overtime->work_date?->toDateString(),
            'hours' => (float) $overtime->hours,
            'status' => $overtime->status,
            'reason' => $overtime->reason,
        ];
    }
}
