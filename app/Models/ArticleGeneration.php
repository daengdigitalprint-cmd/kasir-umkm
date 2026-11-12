<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleGeneration extends Model
{
    public const TYPE_ARTICLE = 'article';

    public const TYPE_TOPICS = 'topics';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type',
        'status',
        'input',
        'result',
        'error',
        'article_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'result' => 'array',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
