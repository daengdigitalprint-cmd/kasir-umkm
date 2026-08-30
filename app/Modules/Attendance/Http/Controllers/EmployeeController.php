<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Models\Store;
use App\Modules\Attendance\Models\Employee;
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

        $employee->update($this->validateData($request, $store, $employee));

        return back()->with('success', 'Data karyawan diperbarui.');
    }

    public function destroy(Employee $employee)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($employee, $store);

        $employee->delete();

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
                Rule::unique('employees', 'user_id')
                    ->where(fn ($query) => $query->where('store_id', $store->id))
                    ->ignore($employee?->id),
            ],
            'is_active' => 'boolean',
        ]);
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
        ];
    }
}
