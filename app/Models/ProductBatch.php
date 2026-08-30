<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBatch extends Model
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_QUARANTINED = 'quarantined';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_DESTROYED = 'destroyed';
    public const STATUS_RETURNED = 'returned';

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_QUARANTINED,
        self::STATUS_EXPIRED,
        self::STATUS_DESTROYED,
        self::STATUS_RETURNED,
    ];

    protected $fillable = [
        'store_id',
        'product_id',
        'quantity_remaining',
        'expiry_date',
        'batch_code',
        'status',
        'quarantine_reason',
        'quarantined_by',
        'quarantined_at',
        'released_by',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_remaining' => 'decimal:4',
            'expiry_date' => 'date',
            'quarantined_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
