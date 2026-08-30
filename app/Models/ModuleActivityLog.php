<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleActivityLog extends Model
{
    protected $table = 'module_activity_logs';

    protected $fillable = [
        'store_id',
        'user_id',
        'module_name',
        'action',
        'status_sebelum',
        'status_sesudah',
        'ip_address',
        'user_agent',
        'keterangan',
    ];

    protected $casts = [
        'status_sebelum' => 'boolean',
        'status_sesudah' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeModule($query, string $moduleName)
    {
        return $query->where('module_name', $moduleName);
    }

    public function scopeForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
