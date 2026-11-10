<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Services\AttendanceService;
use App\Services\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AttendanceReportController extends AdminAttendanceController
{
    public function index(Request $request, AttendanceService $attendance)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $from = $validated['from'] ?? now($store->timezone ?: 'UTC')->startOfMonth()->toDateString();
        $to = $validated['to'] ?? now($store->timezone ?: 'UTC')->toDateString();

        $employees = Employee::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $summary = $employees
            ->map(function (Employee $employee) use ($attendance, $from, $to) {
                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'position' => $employee->position,
                    ...$attendance->aggregate(
                        $employee,
                        CarbonImmutable::parse($from)->startOfDay(),
                        CarbonImmutable::parse($to)->endOfDay(),
                    ),
                ];
            })
            ->values();

        $records = Attendance::query()
            ->where('store_id', $store->id)
            ->whereBetween('work_date', [$from, $to])
            ->with(['employee:id,name', 'photos:id,attendance_id,type,captured_at'])
            ->orderByDesc('work_date')
            ->orderByDesc('clock_in')
            ->get()
            ->map(fn (Attendance $record) => $this->serializeRecord($record))
            ->values();

        return Inertia::render('Attendance/Report', [
            'from' => $from,
            'to' => $to,
            'summary' => $summary,
            'records' => $records,
        ]);
    }

    public function update(Request $request, Attendance $attendance, AttendanceService $service)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        if ($attendance->store_id !== $store->id) {
            abort(404);
        }

        // Kosongkan nilai '' menjadi null agar lolos aturan nullable.
        $request->merge([
            'clock_in' => $request->filled('clock_in') ? $request->input('clock_in') : null,
            'clock_out' => $request->filled('clock_out') ? $request->input('clock_out') : null,
        ]);

        $validated = $request->validate([
            'clock_in' => 'nullable|date_format:H:i',
            'clock_out' => 'nullable|date_format:H:i',
            'reason' => 'required|string|max:255',
        ], [
            'reason.required' => 'Alasan koreksi wajib diisi (tercatat di log aktivitas).',
        ]);

        $before = [
            'clock_in' => $attendance->clock_in?->format('H:i'),
            'clock_out' => $attendance->clock_out?->format('H:i'),
            'status' => $attendance->status,
        ];

        $tz = $store->timezone ?: 'UTC';
        $workDate = $attendance->work_date->toDateString();

        $clockIn = $validated['clock_in']
            ? CarbonImmutable::parse("{$workDate} {$validated['clock_in']}", $tz)
            : null;

        $clockOut = $validated['clock_out']
            ? CarbonImmutable::parse("{$workDate} {$validated['clock_out']}", $tz)
            : null;

        if ($clockOut && ! $clockIn) {
            throw ValidationException::withMessages([
                'clock_out' => 'Jam pulang tidak bisa disimpan tanpa jam masuk.',
            ]);
        }

        // Jam pulang lebih awal dari jam masuk = shift malam, pulang keesokan harinya.
        if ($clockIn && $clockOut && $clockOut->lessThanOrEqualTo($clockIn)) {
            $clockOut = $clockOut->addDay();
        }

        $attendance->forceFill([
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
            'notes' => mb_substr('Koreksi: '.$validated['reason'], 0, 255),
            'updated_by' => auth()->id(),
        ])->save();

        $service->applySchedule($attendance);

        ActivityLogger::log($store, 'attendance.corrected',
            "Koreksi absen {$attendance->employee?->name} tanggal {$workDate}: {$validated['reason']}",
            $attendance,
            [
                'before' => $before,
                'after' => [
                    'clock_in' => $attendance->clock_in?->format('H:i'),
                    'clock_out' => $attendance->clock_out?->format('H:i'),
                    'status' => $attendance->status,
                ],
                'reason' => $validated['reason'],
            ],
        );

        return back()->with('success', 'Jam absen berhasil diperbarui.');
    }

    public function destroy(Attendance $attendance)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        if ($attendance->store_id !== $store->id) {
            abort(404);
        }

        foreach ($attendance->photos as $photo) {
            Storage::disk('local')->delete($photo->path);
        }

        ActivityLogger::log($store, 'attendance.deleted',
            "Menghapus absen {$attendance->employee?->name} tanggal {$attendance->work_date?->toDateString()}",
            $attendance,
            [
                'clock_in' => $attendance->clock_in?->format('H:i'),
                'clock_out' => $attendance->clock_out?->format('H:i'),
                'status' => $attendance->status,
            ],
        );

        $attendance->delete();

        return back()->with('success', 'Absensi berhasil dihapus.');
    }

    private function serializeRecord(Attendance $record): array
    {
        return [
            'id' => $record->id,
            'work_date' => $record->work_date?->toDateString(),
            'employee_name' => $record->employee?->name,
            'clock_in' => $record->clock_in?->format('H:i'),
            'clock_out' => $record->clock_out?->format('H:i'),
            'status' => $record->status,
            'late_minutes' => $record->late_minutes,
            'hours_worked' => $record->hours_worked,
            'overtime_hours' => $record->overtime_hours,
            'notes' => $record->notes,
            'photos' => $record->photos->map(fn ($photo) => [
                'id' => $photo->id,
                'type' => $photo->type,
                'url' => route('attendance.photos.show', $photo),
            ])->values(),
        ];
    }
}
