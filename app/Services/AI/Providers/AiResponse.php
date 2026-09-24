<?php

namespace App\Services\AI\Providers;

class AiResponse
{
    public function __construct(
        public readonly ?string $content,
        public readonly ?array $toolCalls,
        public readonly ?string $toolCallId,
        public readonly ?int $tokensUsed,
        public readonly ?string $finishReason,
        public readonly ?string $error = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->error === null;
    }

    public function hasToolCalls(): bool
    {
        return ! empty($this->toolCalls);
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function getToolCalls(): array
    {
        return $this->toolCalls ?? [];
    }

    public static function error(string $message): self
    {
        return new self(
            content: null,
            toolCalls: null,
            toolCallId: null,
            tokensUsed: null,
            finishReason: 'error',
            error: $message,
        );
    }

    public static function fromOpenAiArray(array $response): self
    {
        $choice = $response['choices'][0] ?? null;

        if (! $choice) {
            return self::error('Tidak ada respons dari AI');
        }

        $message = $choice['message'] ?? null;

        return new self(
            content: $message['content'] ?? null,
            toolCalls: $message['tool_calls'] ?? null,
            toolCallId: null,
            tokensUsed: $response['usage']['total_tokens'] ?? null,
            finishReason: $choice['finish_reason'] ?? null,
        );
    }
}
