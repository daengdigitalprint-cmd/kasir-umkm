<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CashShift extends Model
{
    protected $fillable = [
        'store_id',
        'user_id',
        'employee_id',
        'shift_number',
        'opened_at',
        'closed_at',
        'opening_cash',
        'expected_cash',
        'closing_cash_counted',
        'difference_amount',
        'closing_notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'closing_cash_counted' => 'decimal:2',
            'difference_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CashShift $shift) {
            if (empty($shift->shift_number)) {
                $shift->shift_number = 'STR-'.now()->format('Ymd').'-'.Str::upper(Str::random(4));
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Attendance\Models\Employee::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
