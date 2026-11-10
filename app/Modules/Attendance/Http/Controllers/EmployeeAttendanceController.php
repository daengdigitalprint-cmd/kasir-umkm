<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Models\User;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\LeaveRequest;
use App\Modules\Attendance\Models\OvertimeRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class EmployeeAttendanceController extends AdminAttendanceController
{
    /* ----------------------------------------------------------------
     |  Riwayat Absensi Saya (read-only)
     * ---------------------------------------------------------------- */
    public function history(Request $request)
    {
        $store = $this->currentStore();
        $employee = $this->resolveEmployeeForUser(Auth::user(), $store);

        $from = $request->get('from') ?? now($store->timezone ?: 'UTC')->startOfMonth()->toDateString();
        $to = $request->get('to') ?? now($store->timezone ?: 'UTC')->toDateString();

        $records = \App\Modules\Attendance\Models\Attendance::query()
            ->where('store_id', $store->id)
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from, $to])
            ->orderByDesc('work_date')
            ->orderByDesc('clock_in')
            ->get();

        return Inertia::render('Attendance/History', [
            'records' => $records->map(fn ($r) => [
                'id' => $r->id,
                'work_date' => $r->work_date?->toDateString(),
                'clock_in' => $r->clock_in?->format('H:i'),
                'clock_out' => $r->clock_out?->format('H:i'),
                'status' => $r->status,
                'late_minutes' => $r->late_minutes,
                'hours_worked' => $r->hours_worked,
                'overtime_hours' => $r->overtime_hours,
                'notes' => $r->notes,
            ]),
            'from' => $from,
            'to' => $to,
        ]);
    }

    /* ----------------------------------------------------------------
     |  Pengajuan Cuti Saya
     * ---------------------------------------------------------------- */
    public function myLeaves(Request $request)
    {
        $store = $this->currentStore();
        $employee = $this->resolveEmployeeForUser(Auth::user(), $store);

        $leaves = LeaveRequest::query()
            ->where('store_id', $store->id)
            ->where('employee_id', $employee->id)
            ->with('approver')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'type' => $l->leave_type,
                'start_date' => $l->start_date->toDateString(),
                'end_date' => $l->end_date->toDateString(),
                'reason' => $l->reason,
                'status' => $l->status,
                'reviewed_by' => $l->approver?->name,
                'review_note' => null,
            ]);

        return Inertia::render('Attendance/MyLeaves', [
            'leaves' => $leaves,
        ]);
    }

    public function storeLeave(Request $request)
    {
        $store = $this->currentStore();
        $employee = $this->resolveEmployeeForUser(Auth::user(), $store);

        $validated = $request->validate([
            'type' => 'required|string|in:annual,sick,maternity,marriage,bereavement,unpaid,other',
            'start_date' => 'required|date|before_or_equal:end_date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:255',
        ]);

        LeaveRequest::create([
            'store_id' => $store->id,
            'employee_id' => $employee->id,
            'leave_type' => $validated['type'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'reason' => $validated['reason'] ?? null,
        ]);

        return back()->with('success', 'Pengajuan cuti berhasil dikirim.');
    }

    /* ----------------------------------------------------------------
     |  Pengajuan Lembur Saya
     * ---------------------------------------------------------------- */
    public function myOvertimes(Request $request)
    {
        $store = $this->currentStore();
        $employee = $this->resolveEmployeeForUser(Auth::user(), $store);

        $overtimes = OvertimeRequest::query()
            ->where('store_id', $store->id)
            ->where('employee_id', $employee->id)
            ->with('approver')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'work_date' => $o->work_date->toDateString(),
                'hours' => $o->hours,
                'reason' => $o->reason,
                'status' => $o->status,
                'reviewed_by' => $o->approver?->name,
                'review_note' => null,
            ]);

        return Inertia::render('Attendance/MyOvertimes', [
            'overtimes' => $overtimes,
        ]);
    }

    public function storeOvertime(Request $request)
    {
        $store = $this->currentStore();
        $employee = $this->resolveEmployeeForUser(Auth::user(), $store);

        $validated = $request->validate([
            'work_date' => 'required|date|before_or_equal:today',
            'hours' => 'required|numeric|min:0.5|max:24',
            'reason' => 'nullable|string|max:255',
        ]);

        OvertimeRequest::create([
            'store_id' => $store->id,
            'employee_id' => $employee->id,
            'work_date' => $validated['work_date'],
            'hours' => $validated['hours'],
            'reason' => $validated['reason'] ?? null,
        ]);

        return back()->with('success', 'Pengajuan lembur berhasil dikirim.');
    }

    /* ----------------------------------------------------------------
     |  Helpers
     * ---------------------------------------------------------------- */
    private function resolveEmployeeForUser(User $user, \App\Models\Store $store): Employee
    {
        $employee = Employee::query()
            ->where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->first();

        abort_if(! $employee, 404, 'Profil karyawan belum dibuat untuk akun ini.');

        return $employee;
    }
}
