<?php

namespace App\Models;

use App\Support\ProspectScorer;
use Illuminate\Database\Eloquent\Model;

/**
 * Prospek penjualan dari sumber data terbuka (alat internal pemasaran).
 */
class Prospect extends Model
{
    public const STATUSES = [
        'new' => 'Belum dihubungi',
        'contacted' => 'Sudah dihubungi',
        'interested' => 'Tertarik',
        'not_interested' => 'Tidak tertarik',
    ];

    public const SOURCES = [
        'osm' => 'OpenStreetMap',
        'google' => 'Google Places',
    ];

    protected $fillable = [
        'source',
        'source_id',
        'name',
        'category',
        'keyword',
        'city',
        'address',
        'lat',
        'lng',
        'phone',
        'phone_is_mobile',
        'website',
        'instagram',
        'rating',
        'reviews_count',
        'dedupe_key',
        'score',
        'status',
        'notes',
        'contacted_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'phone_is_mobile' => 'boolean',
            'rating' => 'float',
            'reviews_count' => 'integer',
            'score' => 'integer',
            'contacted_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** Nomor dalam format lokal (08xx / 0411xx) agar mudah dibaca. */
    public function phoneLocal(): ?string
    {
        return $this->phone ? '0'.substr($this->phone, 2) : null;
    }

    public function tier(): string
    {
        return ProspectScorer::tier($this->score);
    }
}
