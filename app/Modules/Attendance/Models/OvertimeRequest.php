<?php

namespace App\Modules\Attendance\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeRequest extends Model
{
    /** Diajukan karyawan/admin. */
    public const SOURCE_REQUEST = 'request';

    /** Terdeteksi otomatis dari jam absen pulang. */
    public const SOURCE_AUTO = 'auto';

    protected $fillable = [
        'store_id',
        'employee_id',
        'attendance_id',
        'work_date',
        'hours',
        'status',
        'source',
        'reason',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'hours' => 'float',
            'approved_at' => 'datetime',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
