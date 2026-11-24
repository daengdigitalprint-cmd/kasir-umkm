<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** F10 — Pesanan antar dengan kurir sendiri. */
class DeliveryOrder extends Model
{
    /** Pesanan dari tautan pesan antar yang belum diterima/ditolak kasir. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_ON_THE_WAY = 'on_the_way';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLED = 'cancelled';

    public const LABELS = [
        self::STATUS_PENDING => 'Menunggu konfirmasi',
        self::STATUS_PREPARING => 'Disiapkan',
        self::STATUS_ON_THE_WAY => 'Diantar',
        self::STATUS_DELIVERED => 'Selesai',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    /** Perpindahan status yang diizinkan. */
    public const TRANSITIONS = [
        // Menunggu konfirmasi hanya keluar lewat aksi Terima/Tolak (DeliveryOrderController::accept/reject).
        self::STATUS_PENDING => [],
        self::STATUS_PREPARING => [self::STATUS_ON_THE_WAY, self::STATUS_CANCELLED],
        self::STATUS_ON_THE_WAY => [self::STATUS_DELIVERED, self::STATUS_PREPARING, self::STATUS_CANCELLED],
        self::STATUS_DELIVERED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'store_id',
        'transaction_id',
        'recipient_name',
        'phone',
        'address',
        'delivery_fee',
        'courier_user_id',
        'courier_name',
        'status',
        'notes',
        'dispatched_at',
        'delivered_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'delivery_fee' => 'decimal:2',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Nama kurir yang ditampilkan. Kurir tidak perlu punya akun: namanya disimpan sebagai teks.
     * Data lama yang memakai akun pengguna (`courier_user_id`) tetap terbaca.
     */
    public function courierLabel(): ?string
    {
        $name = trim((string) $this->courier_name);

        return $name !== '' ? $name : ($this->courier_user_id ? $this->courier?->name : null);
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_user_id');
    }
}
