<?php

namespace App\Tools;

class ToolResult
{
    public function __construct(
        public readonly bool $success,
        public readonly mixed $data = null,
        public readonly ?string $message = null,
        public readonly ?string $error = null,
    ) {}

    public static function success(mixed $data = null, ?string $message = null): self
    {
        return new self(
            success: true,
            data: $data,
            message: $message,
        );
    }

    public static function error(string $message): self
    {
        return new self(
            success: false,
            error: $message,
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function toArray(): array
    {
        if ($this->success) {
            return [
                'success' => true,
                'data' => $this->data,
                'message' => $this->message,
            ];
        }

        return [
            'success' => false,
            'error' => $this->error,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }
}
