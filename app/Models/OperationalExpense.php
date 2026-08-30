<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalExpense extends Model
{
    protected $fillable = [
        'store_id',
        'user_id',
        'category',
        'description',
        'amount',
        'expense_date',
        'payment_method',
        'notes',
        'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
        ];
    }

    public const CATEGORIES = [
        'sewa' => 'Sewa Tempat',
        'gaji' => 'Gaji Karyawan',
        'listrik' => 'Listrik',
        'air' => 'Air',
        'internet' => 'Internet',
        'transport' => 'Transport',
        'lainnya' => 'Lainnya',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }
}
