<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Models\Store;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\LeaveRequest;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Services\EmployeePhotoService;
use App\Modules\Payroll\Models\PayrollRun;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EmployeeController extends AdminAttendanceController
{
    public function index()
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        $employees = Employee::query()
            ->where('store_id', $store->id)
            ->with('user:id,name,email')
            ->orderBy('name')
            ->get()
            ->map(fn (Employee $employee) => $this->serialize($employee))
            ->values();

        return Inertia::render('Attendance/Employees', [
            'employees' => $employees,
            'users' => $this->availableUsers($store),
        ]);
    }

    public function store(Request $request)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        Employee::create([
            'store_id' => $store->id,
            ...$this->validateData($request, $store),
        ]);

        return back()->with('success', 'Karyawan ditambahkan.');
    }

    public function update(Request $request, Employee $employee)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($employee, $store);

        $before = $employee->only(['name', 'position', 'user_id', 'is_active', 'joined_at', 'resigned_at']);
        $employee->update($this->validateData($request, $store, $employee));
        $changes = array_diff_key($employee->getChanges(), array_flip(['updated_at']));

        if ($changes !== []) {
            ActivityLogger::log($store, 'employee.updated', "Mengubah data karyawan {$employee->name}", $employee, [
                'before' => array_intersect_key($before, $changes),
                'after' => $changes,
            ]);
        }

        return back()->with('success', 'Data karyawan diperbarui.');
    }

    public function destroy(Employee $employee)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($employee, $store);

        // Riwayat absensi & gaji dipakai payroll, pajak tahunan, dan audit: jangan dihapus.
        $hasHistory = Attendance::query()->where('employee_id', $employee->id)->exists()
            || PayrollRun::withTrashed()->where('employee_id', $employee->id)->exists()
            || LeaveRequest::query()->where('employee_id', $employee->id)->exists()
            || OvertimeRequest::query()->where('employee_id', $employee->id)->exists();

        if ($hasHistory) {
            return back()->with('error', "{$employee->name} sudah punya riwayat absensi/gaji, jadi tidak dihapus. Nonaktifkan saja dan isi tanggal keluar.");
        }

        $employee->delete();

        ActivityLogger::log($store, 'employee.deleted', "Menghapus karyawan {$employee->name}", $employee);

        return back()->with('success', 'Karyawan dihapus.');
    }

    private function validateData(Request $request, Store $store, ?Employee $employee = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'position' => 'nullable|string|max:100',
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                // Hanya akun anggota toko ini yang boleh ditautkan (cegah bocor nama/email user lain).
                function (string $attribute, mixed $value, \Closure $fail) use ($store) {
                    if (! $store->members()->whereKey($value)->exists()) {
                        $fail('Akun pengguna bukan anggota toko ini.');
                    }
                },
                Rule::unique('employees', 'user_id')
                    ->where(fn ($query) => $query->where('store_id', $store->id))
                    ->ignore($employee?->id),
            ],
            'is_active' => 'boolean',
            // Dipakai payroll untuk gaji prorata saat masuk/keluar di tengah periode.
            'joined_at' => 'nullable|date',
            'resigned_at' => 'nullable|date|after_or_equal:joined_at',
        ]);
    }

    /**
     * Foto profil karyawan untuk admin toko.
     */
    public function photo(Employee $employee, EmployeePhotoService $photos)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($employee, $store);

        return $photos->response($employee);
    }

    /**
     * Lepas HP terdaftar (aplikasi Absensi Android) agar karyawan bisa absen dari HP baru.
     */
    public function resetDevice(Employee $employee)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($employee, $store);

        if (! $employee->device_id) {
            return back()->with('error', 'Karyawan ini belum mendaftarkan HP.');
        }

        $old = $employee->device_name;
        // Kunci HP berlaku per orang di semua outlet, jadi dilepas dari semua data karyawannya.
        Employee::query()
            ->when(
                $employee->user_id,
                fn ($query) => $query->where('user_id', $employee->user_id)->where('device_id', $employee->device_id),
                fn ($query) => $query->whereKey($employee->id),
            )
            ->update(['device_id' => null, 'device_name' => null, 'device_bound_at' => null]);

        ActivityLogger::log($store, 'attendance.device_reset', "Melepas HP {$old} milik {$employee->name}", $employee, ['device_name' => $old]);

        return back()->with('success', "HP {$employee->name} dilepas. HP berikutnya yang dipakai absen akan terdaftar otomatis.");
    }

    private function ensureOwned(Employee $employee, Store $store): void
    {
        abort_if($employee->store_id !== $store->id, 404);
    }

    private function availableUsers(Store $store): array
    {
        return $store->members()
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->all();
    }

    private function serialize(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'position' => $employee->position,
            'user_id' => $employee->user_id,
            'user_name' => $employee->user?->name,
            'user_email' => $employee->user?->email,
            'is_active' => (bool) $employee->is_active,
            'joined_at' => $employee->joined_at?->toDateString(),
            'resigned_at' => $employee->resigned_at?->toDateString(),
            'device_name' => $employee->device_name,
            'photo_url' => $employee->photo_path
                ? route('attendance.employees.photo', $employee).'?v='.EmployeePhotoService::version($employee)
                : null,
            'device_bound_at' => $employee->device_bound_at?->toDateTimeString(),
        ];
    }
}
