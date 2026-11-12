<?php

namespace App\Models;

use App\Services\ArticleContentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Article extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHED = 'published';

    /** Strategi SEO yang didukung fitur generate. */
    public const STRATEGIES = [
        'pillar' => 'Artikel Pilar (panduan lengkap)',
        'cluster' => 'Artikel Cluster (topik spesifik, link ke pilar)',
        'howto' => 'How-to / Tutorial',
        'listicle' => 'Listicle (daftar tips)',
        'comparison' => 'Perbandingan',
        'faq' => 'FAQ / Long-tail',
        'programmatic' => 'Per Jenis Usaha (programmatic)',
    ];

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'content_markdown',
        'content_html',
        'featured_image',
        'featured_image_alt',
        'category',
        'tags',
        'faq',
        'focus_keyword',
        'meta_title',
        'meta_description',
        'canonical_url',
        'status',
        'published_at',
        'is_pillar',
        'strategy',
        'reading_time',
        'views',
        'generated_by_ai',
        'ai_model',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'faq' => 'array',
            'published_at' => 'datetime',
            'is_pillar' => 'boolean',
            'generated_by_ai' => 'boolean',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Artikel yang tampil publik: published, atau scheduled yang waktunya
     * sudah tiba (terjadwal otomatis tayang tanpa perlu cron).
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PUBLISHED, self::STATUS_SCHEDULED])
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return in_array($this->status, [self::STATUS_PUBLISHED, self::STATUS_SCHEDULED], true)
            && $this->published_at !== null
            && $this->published_at->isPast();
    }

    /** URL publik artikel. */
    public function publicUrl(): string
    {
        return url('/blog/'.$this->slug);
    }

    /** URL penuh thumbnail (null bila belum ada). */
    public function featuredImageUrl(): ?string
    {
        if ($this->featured_image === null || $this->featured_image === '') {
            return null;
        }

        return asset('storage/'.$this->featured_image);
    }

    /**
     * Alt thumbnail: yang diisi admin, atau disusun otomatis dari judul.
     * Tidak pernah mengembalikan string kosong selama thumbnail ada.
     */
    public function featuredImageAlt(): string
    {
        $alt = trim((string) $this->featured_image_alt);

        if ($alt !== '') {
            return $alt;
        }

        return ArticleContentService::thumbnailAlt($this->title, $this->focus_keyword);
    }

    /** Buat slug unik dari judul. */
    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($title, 80, ''));

        if ($base === '') {
            $base = 'artikel';
        }

        $slug = $base;
        $i = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /** Estimasi waktu baca (menit) dari konten markdown. */
    public static function estimateReadingTime(?string $markdown): int
    {
        if ($markdown === null || trim($markdown) === '') {
            return 0;
        }

        $words = str_word_count(strip_tags($markdown));

        return max(1, (int) ceil($words / 200));
    }

    /** Artikel terkait: kategori sama, lalu artikel terbaru lain. */
    public function related(int $limit = 3)
    {
        $related = static::published()
            ->where('id', '!=', $this->id)
            ->when($this->category, fn ($q) => $q->where('category', $this->category))
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        if ($related->count() < $limit) {
            $more = static::published()
                ->where('id', '!=', $this->id)
                ->whereNotIn('id', $related->pluck('id'))
                ->orderByDesc('published_at')
                ->limit($limit - $related->count())
                ->get();

            $related = $related->concat($more);
        }

        return $related;
    }
}
