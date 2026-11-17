<?php

namespace App\Models;

use App\Support\UpsellSuggestions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** F8 — pasangan manual: saat `trigger` masuk keranjang, tawarkan `suggested`. */
class UpsellRule extends Model
{
    protected $fillable = [
        'store_id',
        'trigger_product_id',
        'suggested_product_id',
        'sort',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Peta saran di-cache per toko; aturan berubah → hitung ulang.
        static::saved(fn (UpsellRule $rule) => UpsellSuggestions::forget($rule->store_id));
        static::deleted(fn (UpsellRule $rule) => UpsellSuggestions::forget($rule->store_id));
    }

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'trigger_product_id');
    }

    public function suggested(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'suggested_product_id');
    }
}
