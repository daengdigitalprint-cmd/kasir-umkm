<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'user_id',
        'filename',
        'filepath',
        'file_size',
        'status',
        'trigger_type',
        'duration_ms',
        'records_count',
        'records_summary',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'duration_ms' => 'integer',
            'records_count' => 'integer',
            'records_summary' => 'array',
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

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isManual(): bool
    {
        return $this->trigger_type === 'manual';
    }

    public function isScheduled(): bool
    {
        return $this->trigger_type === 'scheduled';
    }
}
