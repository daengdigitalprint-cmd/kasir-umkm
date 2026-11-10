<?php

namespace App\Modules\Attendance\Models;

use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hari libur toko (libur nasional / cuti bersama / libur toko sendiri).
 * Pada tanggal ini karyawan tidak ditandai alpa, dan lembur dibayar dengan tarif hari libur.
 */
class Holiday extends Model
{
    protected $table = 'attendance_holidays';

    protected $fillable = [
        'store_id',
        'date',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Daftar tanggal libur (Y-m-d) toko dalam rentang, sebagai set untuk pencarian cepat.
     *
     * @return array<string, true>
     */
    public static function datesBetween(int $storeId, CarbonInterface $from, CarbonInterface $to): array
    {
        return static::query()
            ->where('store_id', $storeId)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->pluck('date')
            ->mapWithKeys(fn ($date) => [$date->toDateString() => true])
            ->all();
    }
}
