<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_COGS = 'cogs';

    public const TYPE_EXPENSE = 'expense';

    public const GROUP_CURRENT_ASSET = 'current_asset';

    public const GROUP_NON_CURRENT_ASSET = 'non_current_asset';

    public const GROUP_CURRENT_LIABILITY = 'current_liability';

    public const GROUP_NON_CURRENT_LIABILITY = 'non_current_liability';

    public const GROUP_EQUITY = 'equity';

    protected $fillable = [
        'store_id',
        'code',
        'name',
        'type',
        'balance_sheet_group',
        'sort_order',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'chart_of_account_id');
    }
}
