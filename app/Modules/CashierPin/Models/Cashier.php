<?php

namespace App\Modules\CashierPin\Models;

use App\Models\CashShift;
use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cashier extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'position',
        'pin_hash',
        'pin_set_at',
        'can_login_pos',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'can_login_pos' => 'boolean',
            'is_active' => 'boolean',
            'pin_set_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function cashShifts(): HasMany
    {
        return $this->hasMany(CashShift::class);
    }

    public function hasPin(): bool
    {
        return ! empty($this->pin_hash);
    }
}
