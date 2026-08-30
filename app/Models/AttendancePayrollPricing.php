<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendancePayrollPricing extends Model
{
    protected $table = 'attendance_payroll_pricing';

    protected $fillable = [
        'monthly_price',
        'yearly_price',
    ];

    protected $casts = [
        'monthly_price' => 'integer',
        'yearly_price' => 'integer',
    ];

    /**
     * Satu baris harga add-on Absensi + Payroll (semua toko memakai yang sama).
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'monthly_price' => 49000,
            'yearly_price' => 490000,
        ]);
    }

    /**
     * @return array{monthly_price: int, yearly_price: int}
     */
    public function toPayload(): array
    {
        return [
            'monthly_price' => (int) $this->monthly_price,
            'yearly_price' => (int) $this->yearly_price,
        ];
    }
}
