<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Table extends Model
{
    protected $table = 'tables';

    protected $fillable = [
        'store_id',
        'name',
        'code',
        'qr_code_url',
        'slug',
        'is_active',
        'capacity',
        'location',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'capacity' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($table) {
            if (empty($table->slug)) {
                $table->slug = Str::lower(Str::random(8));
            }
            if (empty($table->code)) {
                $table->code = null;
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function guestUrl(): string
    {
        return url("/m/{$this->slug}");
    }
}
