<?php

namespace App\Tools;

use App\Models\User;

abstract class AiTool
{
    abstract public function getName(): string;

    abstract public function getDescription(): string;

    abstract public function getParameters(): array;

    abstract public function execute(array $args, User $user): ToolResult;

    public function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $this->getParameters(),
                    'required' => $this->getRequiredParameters(),
                ],
            ],
        ];
    }

    protected function getRequiredParameters(): array
    {
        return [];
    }

    protected function validateArgs(array $args, array $required): void
    {
        foreach ($required as $key) {
            if (! isset($args[$key]) && ! array_key_exists($key, $args)) {
                throw new \InvalidArgumentException("Parameter '{$key}' wajib diisi");
            }
        }
    }

    protected function formatCurrency(float $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }

    protected function formatDate($date): string
    {
        if (is_string($date)) {
            $date = \Carbon\Carbon::parse($date);
        }

        return $date->format('d/m/Y H:i');
    }
}
