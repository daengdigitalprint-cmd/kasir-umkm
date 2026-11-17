<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CentralKitchenPricing extends Model
{
    protected $table = 'central_kitchen_pricing';

    protected $fillable = [
        'monthly_price',
        'yearly_price',
    ];

    protected $casts = [
        'monthly_price' => 'integer',
        'yearly_price' => 'integer',
    ];

    /** Satu baris harga add-on Gudang Pusat Pro (semua grup usaha memakai yang sama). */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'monthly_price' => 199000,
            'yearly_price' => 1990000,
        ]);
    }

    /** @return array{monthly_price: int, yearly_price: int} */
    public function toPayload(): array
    {
        return [
            'monthly_price' => (int) $this->monthly_price,
            'yearly_price' => (int) $this->yearly_price,
        ];
    }
}
