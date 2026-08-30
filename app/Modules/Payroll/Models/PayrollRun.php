<?php

namespace App\Modules\Payroll\Models;

use App\Models\Store;
use App\Modules\Attendance\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollRun extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'store_id',
        'payroll_period_id',
        'employee_id',
        'basic_salary',
        'deductions',
        'overtime_hours',
        'overtime_pay',
        'gross_salary',
        'net_salary',
        'attendance_summary',
        'component_breakdown',
        'deleted_by',
    ];

    protected $casts = [
        'basic_salary' => 'decimal:2',
        'deductions' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'overtime_pay' => 'decimal:2',
        'gross_salary' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'attendance_summary' => 'array',
        'component_breakdown' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
