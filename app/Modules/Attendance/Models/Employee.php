<?php

namespace App\Modules\Attendance\Models;

use App\Models\Store;
use App\Models\User;
use App\Modules\Payroll\Models\SalaryComponent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class Employee extends Model
{
    protected $fillable = [
        'store_id',
        'user_id',
        'name',
        'position',
        'photo_path',
        'joined_at',
        'resigned_at',
        'is_active',
        'tax_status',
        'dependents',
        'pin_hash',
        'pin_set_at',
        'can_login_pos',
        'device_id',
        'device_name',
        'device_bound_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'joined_at' => 'date',
            'resigned_at' => 'date',
            'can_login_pos' => 'boolean',
            'pin_set_at' => 'datetime',
            'device_bound_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function workSchedules(): HasMany
    {
        return $this->hasMany(WorkSchedule::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function overtimeRequests(): HasMany
    {
        return $this->hasMany(OvertimeRequest::class);
    }

    public function salaryComponents(): HasMany
    {
        return $this->hasMany(SalaryComponent::class);
    }

    public function cashShifts(): HasMany
    {
        return $this->hasMany(\App\Models\CashShift::class);
    }

    public function hasPin(): bool
    {
        return ! empty($this->pin_hash);
    }

    public function verifyPin(string $pin): bool
    {
        if (! $this->hasPin()) {
            return false;
        }

        return Hash::check($pin, $this->pin_hash);
    }
}
