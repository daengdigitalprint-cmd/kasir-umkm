<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Grup pilihan tambahan (mis. "Gula", "Topping") yang dibuat sekali per toko
 * lalu ditempel ke banyak produk.
 */
class ModifierGroup extends Model
{
    public const SELECTION_SINGLE = 'single';

    public const SELECTION_MULTIPLE = 'multiple';

    /** Awalan kunci opsi grup di keranjang/transaksi: g{id}:{key}. */
    public const KEY_PREFIX = 'g';

    protected $fillable = [
        'store_id',
        'name',
        'selection',
        'is_required',
        'options',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'options' => 'array',
        'sort_order' => 'integer',
    ];

    protected $hidden = ['pivot'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'modifier_group_product');
    }

    /** Kunci unik opsi di tingkat baris transaksi, agar tidak bentrok antar grup. */
    public function optionKey(string $key): string
    {
        return self::KEY_PREFIX.$this->id.':'.$key;
    }
}
