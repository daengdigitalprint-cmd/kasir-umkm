<?php

namespace App\Modules\Payroll\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollPeriod extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'store_id',
        'name',
        'period_start',
        'period_end',
        'status',
        'deleted_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'deleted_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(PayrollRun::class, 'payroll_period_id');
    }
}
