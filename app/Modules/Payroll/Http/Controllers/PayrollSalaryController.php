<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Attendance\Models\Employee;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Services\TaxService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PayrollSalaryController extends AdminPayrollController
{
    public function index()
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        $employees = Employee::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->with('salaryComponents')
            ->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'position' => $e->position,
                'tax_status' => $e->tax_status ?? 'TK/0',
                'dependents' => $e->dependents ?? 0,
                'components' => $e->salaryComponents
                    ->sortBy('sort_order')
                    ->values()
                    ->map(fn (SalaryComponent $c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'type' => $c->type,
                        'amount' => (float) $c->amount,
                        'sort_order' => $c->sort_order,
                    ]),
                'total_earning' => round($e->salaryComponents->where('type', 'earning')->sum('amount'), 2),
                'total_deduction' => round($e->salaryComponents->where('type', 'deduction')->sum('amount'), 2),
            ]);

        return Inertia::render('Payroll/Salaries', [
            'employees' => $employees,
            'ptkp_options' => TaxService::getPtkpOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        $validated = $request->validate([
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($q) => $q->where('store_id', $store->id)),
            ],
            'name' => 'required|string|max:100',
            'type' => 'required|in:earning,deduction',
            'amount' => 'required|numeric|min:0',
            'sort_order' => 'nullable|integer|min:0|max:255',
        ]);

        SalaryComponent::create([
            'store_id' => $store->id,
            'employee_id' => $validated['employee_id'],
            'name' => $validated['name'],
            'type' => $validated['type'],
            'amount' => $validated['amount'],
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return back()->with('success', 'Komponen gaji ditambahkan.');
    }

    public function update(Request $request, SalaryComponent $component)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($component, $store);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|in:earning,deduction',
            'amount' => 'required|numeric|min:0',
            'sort_order' => 'nullable|integer|min:0|max:255',
        ]);

        $component->update([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'amount' => $validated['amount'],
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return back()->with('success', 'Komponen gaji diperbarui.');
    }

    public function destroy(SalaryComponent $component)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($component, $store);

        $component->delete();

        return back()->with('success', 'Komponen gaji dihapus.');
    }

    public function updateTax(Request $request, Employee $employee)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        abort_if($employee->store_id !== $store->id, 404);

        $validated = $request->validate([
            'tax_status' => 'required|string|in:TK/0,TK/1,TK/2,TK/3,K/0,K/1,K/2,K/3',
        ]);

        $employee->update([
            'tax_status' => $validated['tax_status'],
        ]);

        return back()->with('success', 'Status pajak karyawan diperbarui.');
    }

    private function ensureOwned(SalaryComponent $component, \App\Models\Store $store): void
    {
        abort_if($component->store_id !== $store->id, 404);
    }
}
