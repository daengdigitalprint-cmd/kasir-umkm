<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kabar untuk afiliator yang tampil di dashboardnya (pendaftar baru, komisi, pencairan). */
class AffiliateNotification extends Model
{
    protected $fillable = ['affiliate_id', 'type', 'title', 'body', 'read_at'];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
