<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConfiguration extends Model
{
    protected $fillable = [
        'store_id',
        'provider',
        'base_url',
        'model_id',
        'api_key',
        'max_tokens',
        'temperature',
        'is_active',
        'supports_function_calling',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'supports_function_calling' => 'boolean',
            'temperature' => 'decimal:2',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForStore($query, ?int $storeId)
    {
        return $query->where('store_id', $storeId)->orWhereNull('store_id');
    }
}
