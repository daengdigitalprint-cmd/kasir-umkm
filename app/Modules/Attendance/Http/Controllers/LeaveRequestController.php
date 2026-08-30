<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Models\Store;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\LeaveRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeaveRequestController extends AdminAttendanceController
{
    public function index()
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        $leaves = LeaveRequest::query()
            ->where('store_id', $store->id)
            ->with('employee:id,name,position')
            ->latest()
            ->get()
            ->map(fn (LeaveRequest $leave) => $this->serialize($leave))
            ->values();

        return Inertia::render('Attendance/Leaves', [
            'leaves' => $leaves,
            'employees' => $this->employees($store),
        ]);
    }

    public function store(Request $request)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        LeaveRequest::create([
            'store_id' => $store->id,
            ...$this->validateData($request),
        ]);

        return back()->with('success', 'Pengajuan cuti disimpan.');
    }

    public function update(Request $request, LeaveRequest $leaf)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($leaf, $store);

        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);

        $leaf->status = $validated['status'];
        $leaf->approved_by = $validated['status'] === 'approved' ? auth()->id() : null;
        $leaf->approved_at = $validated['status'] === 'approved' ? now() : null;
        $leaf->save();

        return back()->with('success', $validated['status'] === 'approved'
            ? 'Cuti disetujui.'
            : 'Cuti ditolak.');
    }

    public function destroy(LeaveRequest $leaf)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($leaf, $store);

        $leaf->delete();

        return back()->with('success', 'Pengajuan cuti dihapus.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'leave_type' => 'required|string|max:30',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:1000',
        ]);
    }

    private function ensureOwned(LeaveRequest $leave, Store $store): void
    {
        abort_if($leave->store_id !== $store->id, 404);
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

    private function serialize(LeaveRequest $leave): array
    {
        return [
            'id' => $leave->id,
            'employee_id' => $leave->employee_id,
            'employee_name' => $leave->employee?->name,
            'leave_type' => $leave->leave_type,
            'start_date' => $leave->start_date?->toDateString(),
            'end_date' => $leave->end_date?->toDateString(),
            'status' => $leave->status,
            'reason' => $leave->reason,
        ];
    }
}
