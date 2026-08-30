<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashTransaction extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:4',
    ];

    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    public const CATEGORY_REVENUE = 'revenue';

    public const CATEGORY_NON_REVENUE = 'non_revenue';

    public const CATEGORY_EXPENSE = 'expense';

    public const CATEGORY_NON_EXPENSE = 'non_expense';

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            self::CATEGORY_REVENUE => 'Pendapatan Lain',
            self::CATEGORY_NON_REVENUE => 'Non Pendapatan',
            self::CATEGORY_EXPENSE => 'Biaya',
            self::CATEGORY_NON_EXPENSE => 'Non Biaya',
            default => $category,
        };
    }

    public static function typeLabel(string $type): string
    {
        return $type === self::TYPE_IN ? 'Uang Masuk' : 'Uang Keluar';
    }

    /** @return array<string, string> */
    public static function categoriesForType(string $type): array
    {
        return $type === self::TYPE_IN
            ? [
                self::CATEGORY_REVENUE => 'Pendapatan Lain',
                self::CATEGORY_NON_REVENUE => 'Non Pendapatan',
            ]
            : [
                self::CATEGORY_EXPENSE => 'Biaya',
                self::CATEGORY_NON_EXPENSE => 'Non Biaya',
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

    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(\App\Models\JournalEntry::class);
    }
}
