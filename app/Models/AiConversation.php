<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiConversation extends Model
{
    protected $fillable = [
        'user_id',
        'store_id',
        'title',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->oldest();
    }

    public function latestMessage()
    {
        return $this->hasOne(AiMessage::class, 'conversation_id')->latestOfMany();
    }

    public function getSummaryAttribute(): string
    {
        $lastMessage = $this->latestMessage;
        if (! $lastMessage) {
            return 'Percakapan baru';
        }

        return Str::limit($lastMessage->content ?? '', 50);
    }
}
