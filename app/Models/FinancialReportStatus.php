<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialReportStatus extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINAL = 'final';

    protected $fillable = [
        'store_id',
        'report_type',
        'period_end',
        'status',
        'finalized_by',
        'finalized_at',
        'report_number',
        'issued_at',
        'prepared_by',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'period_end' => 'date',
            'finalized_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }
}
