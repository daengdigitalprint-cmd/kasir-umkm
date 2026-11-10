<?php

namespace App\Modules\Payroll\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollPeriod extends Model
{
    use SoftDeletes;

    /** Draft: boleh dihitung ulang & dihapus. */
    public const STATUS_DRAFT = 'draft';

    /** Disetujui: angka dibekukan; bisa dibuka kembali ke draft atau ditandai dibayar. */
    public const STATUS_APPROVED = 'approved';

    /** Dibayar: final, jurnal akuntansi sudah diposting. */
    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'store_id',
        'name',
        'period_start',
        'period_end',
        'status',
        'approved_by',
        'approved_at',
        'paid_by',
        'paid_at',
        'payment_date',
        'payment_method',
        'journal_entry_id',
        'deleted_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'deleted_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'payment_date' => 'date',
    ];

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(PayrollRun::class, 'payroll_period_id');
    }
}
