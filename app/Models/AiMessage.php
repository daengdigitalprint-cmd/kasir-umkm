<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'tool_calls',
        'tool_call_id',
        'tokens_used',
        'feedback',
    ];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    public function isAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    public function isTool(): bool
    {
        return $this->role === 'tool';
    }

    public function hasToolCalls(): bool
    {
        return $this->role === 'assistant' && ! empty($this->tool_calls);
    }

    public function hasFeedback(): bool
    {
        return $this->feedback !== null;
    }

    public function isPositive(): bool
    {
        return $this->feedback === 'positive';
    }

    public function isNegative(): bool
    {
        return $this->feedback === 'negative';
    }
}
