<?php

namespace App\Services\AI;

use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Facades\Log;

class AiToolRegistry
{
    protected array $tools = [];

    public function register(AiTool $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function get(string $name): ?AiTool
    {
        return $this->tools[$name] ?? null;
    }

    public function all(): array
    {
        return $this->tools;
    }

    public function getToolDefinitions(): array
    {
        return array_map(fn (AiTool $tool) => $tool->getDefinition(), array_values($this->tools));
    }

    public function execute(string $name, array $args, $user): ToolResult
    {
        $tool = $this->get($name);

        if (! $tool) {
            Log::error("AI Tool not found: {$name}");

            return ToolResult::error("Tool '{$name}' tidak ditemukan");
        }

        try {
            return $tool->execute($args, $user);
        } catch (\Exception $e) {
            Log::error("AI Tool execution failed: {$name}", [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return ToolResult::error("Gagal menjalankan tool: {$e->getMessage()}");
        }
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function count(): int
    {
        return count($this->tools);
    }
}
