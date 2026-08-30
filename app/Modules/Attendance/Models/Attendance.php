<?php

namespace App\Modules\Attendance\Models;

use App\Models\CashShift;
use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attendance extends Model
{
    public const STATUS_PRESENT = 'present';

    public const STATUS_LATE = 'late';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_LEAVE = 'leave';

    public const STATUS_SICK = 'sick';

    public const STATUS_HOLIDAY = 'holiday';

    public const STATUS_DAY_OFF = 'day_off';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_CASH_SHIFT = 'cash_shift';

    public const SOURCE_DEVICE = 'device';

    public const SOURCE_IMPORTED = 'imported';

    protected $fillable = [
        'store_id',
        'employee_id',
        'work_date',
        'schedule_id',
        'clock_in',
        'clock_in_latitude',
        'clock_in_longitude',
        'clock_in_accuracy',
        'clock_out',
        'clock_out_latitude',
        'clock_out_longitude',
        'clock_out_accuracy',
        'status',
        'source',
        'cash_shift_id',
        'late_minutes',
        'early_leave_minutes',
        'hours_worked',
        'overtime_hours',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
            'clock_in_latitude' => 'float',
            'clock_in_longitude' => 'float',
            'clock_in_accuracy' => 'float',
            'clock_out_latitude' => 'float',
            'clock_out_longitude' => 'float',
            'clock_out_accuracy' => 'float',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'hours_worked' => 'float',
            'overtime_hours' => 'float',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'schedule_id');
    }

    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(AttendancePhoto::class);
    }
}
