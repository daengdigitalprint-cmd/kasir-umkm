<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiCompatibleProvider implements AiProviderInterface
{
    protected string $baseUrl;

    protected string $modelId;

    protected string $apiKey;

    protected int $maxTokens;

    protected float $temperature;

    protected bool $supportsFunctionCalling;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
        $this->modelId = $config['model_id'] ?? 'gpt-4o';
        $this->apiKey = $config['api_key'] ?? '';
        $this->maxTokens = (int) ($config['max_tokens'] ?? 4096);
        $this->temperature = (float) ($config['temperature'] ?? 0.7);
        $this->supportsFunctionCalling = (bool) ($config['supports_function_calling'] ?? true);
    }

    public function supportsFunctionCalling(): bool
    {
        return $this->supportsFunctionCalling;
    }

    public function chat(array $messages, array $tools = []): AiResponse
    {
        try {
            $payload = [
                'model' => $this->modelId,
                'messages' => $messages,
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
            ];

            if (! empty($tools) && $this->supportsFunctionCalling) {
                $payload['tools'] = $tools;
                $payload['tool_choice'] = 'auto';
            }

            $timeout = (int) config('ai.timeout', 45);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout($timeout)
                ->post("{$this->baseUrl}/chat/completions", $payload);

            if ($response->failed()) {
                Log::error('AI Provider Error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return AiResponse::error($this->friendlyApiError($response->status(), $response->body()));
            }

            return AiResponse::fromOpenAiArray($response->json());

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('AI Provider Timeout', [
                'base_url' => $this->baseUrl,
                'model' => $this->modelId,
                'message' => $e->getMessage(),
            ]);

            return AiResponse::error('Waktu respons AI habis. Coba ulangi beberapa detik lagi. Jika sering terjadi, hubungi admin untuk cek koneksi AI.');
        } catch (\Exception $e) {
            Log::error('AI Provider Exception', [
                'message' => $e->getMessage(),
            ]);

            return AiResponse::error($this->friendlyConnectionError($e->getMessage()));
        }
    }

    public function chatStream(array $messages, array $tools = [], ?callable $onDelta = null): AiResponse
    {
        try {
            $payload = [
                'model' => $this->modelId,
                'messages' => $messages,
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
                'stream' => true,
            ];

            if (! empty($tools) && $this->supportsFunctionCalling) {
                $payload['tools'] = $tools;
                $payload['tool_choice'] = 'auto';
            }

            $timeout = (int) config('ai.timeout', 45);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
            ])
                ->withOptions(['stream' => true])
                ->timeout($timeout)
                ->post("{$this->baseUrl}/chat/completions", $payload);

            $stream = $response->toPsrResponse()->getBody();

            if ($response->failed()) {
                $body = $stream->getContents();

                Log::error('AI Provider Error (stream)', [
                    'status' => $response->status(),
                    'body' => $body,
                ]);

                return AiResponse::error($this->friendlyApiError($response->status(), $body));
            }

            return $this->parseSseStream($stream, $onDelta);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('AI Provider Timeout (stream)', [
                'base_url' => $this->baseUrl,
                'model' => $this->modelId,
                'message' => $e->getMessage(),
            ]);

            return AiResponse::error('Waktu respons AI habis. Coba ulangi beberapa detik lagi. Jika sering terjadi, hubungi admin untuk cek koneksi AI.');
        } catch (\Exception $e) {
            Log::error('AI Provider Exception (stream)', [
                'message' => $e->getMessage(),
            ]);

            return AiResponse::error($this->friendlyConnectionError($e->getMessage()));
        }
    }

    /**
     * Parse aliran Server-Sent Events dari provider dan kumpulkan respons lengkap.
     *
     * @param  \Psr\Http\Message\StreamInterface  $stream
     * @param  callable|null  $onDelta  fn (string $delta): void
     */
    protected function parseSseStream($stream, ?callable $onDelta): AiResponse
    {
        $content = '';
        $toolCalls = [];
        $finishReason = null;
        $buffer = '';

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);

            if ($chunk === '' || $chunk === false) {
                if ($stream->eof()) {
                    break;
                }

                continue;
            }

            $buffer .= $chunk;

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $newline), "\r");
                $buffer = substr($buffer, $newline + 1);

                if (! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));

                if ($data === '') {
                    continue;
                }

                if ($data === '[DONE]') {
                    return $this->buildStreamedResponse($content, $toolCalls, $finishReason);
                }

                $payload = json_decode($data, true);

                if (! is_array($payload)) {
                    continue;
                }

                $choice = $payload['choices'][0] ?? null;

                if (! is_array($choice)) {
                    continue;
                }

                $delta = $choice['delta'] ?? [];

                $deltaContent = $delta['content'] ?? null;
                if (is_string($deltaContent) && $deltaContent !== '') {
                    $content .= $deltaContent;

                    if ($onDelta) {
                        $onDelta($deltaContent);
                    }
                }

                foreach ($delta['tool_calls'] ?? [] as $toolCallDelta) {
                    $index = (int) ($toolCallDelta['index'] ?? 0);
                    $toolCalls[$index] ??= [
                        'id' => '',
                        'type' => 'function',
                        'function' => ['name' => '', 'arguments' => ''],
                    ];

                    if (isset($toolCallDelta['id'])) {
                        $toolCalls[$index]['id'] .= $toolCallDelta['id'];
                    }
                    if (isset($toolCallDelta['function']['name'])) {
                        $toolCalls[$index]['function']['name'] .= $toolCallDelta['function']['name'];
                    }
                    if (isset($toolCallDelta['function']['arguments'])) {
                        $toolCalls[$index]['function']['arguments'] .= $toolCallDelta['function']['arguments'];
                    }
                }

                if (! empty($choice['finish_reason'])) {
                    $finishReason = $choice['finish_reason'];
                }
            }
        }

        return $this->buildStreamedResponse($content, $toolCalls, $finishReason);
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     */
    protected function buildStreamedResponse(string $content, array $toolCalls, ?string $finishReason): AiResponse
    {
        ksort($toolCalls);

        return new AiResponse(
            content: $content !== '' ? $content : null,
            toolCalls: $toolCalls !== [] ? array_values($toolCalls) : null,
            toolCallId: null,
            tokensUsed: null,
            finishReason: $finishReason,
        );
    }

    protected function friendlyApiError(int $status, string $body): string
    {
        if ($status === 401 || $status === 403) {
            return 'Kunci API AI tidak valid atau kedaluwarsa. Hubungi admin untuk perbarui pengaturan AI.';
        }

        if ($status === 429) {
            return 'Batas pemakaian AI tercapai. Silakan coba lagi beberapa menit kemudian.';
        }

        if ($status >= 500) {
            return 'Provider AI sedang gangguan. Silakan coba lagi beberapa saat.';
        }

        return 'Provider AI menolak permintaan. Silakan coba lagi atau hubungi admin jika berlanjut.';
    }

    protected function friendlyConnectionError(string $message): string
    {
        if (str_contains(strtolower($message), 'curl') || str_contains(strtolower($message), 'resolve') || str_contains(strtolower($message), 'connect')) {
            return 'Gagal terhubung ke provider AI. Periksa koneksi atau alamat base URL di pengaturan AI.';
        }

        return 'Gagal terhubung ke provider AI. Silakan coba lagi beberapa saat.';
    }

    public function validateConnection(): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])
                ->timeout(10)
                ->get("{$this->baseUrl}/models");

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getModels(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])
                ->timeout(10)
                ->get("{$this->baseUrl}/models");

            if ($response->failed()) {
                return [];
            }

            $models = $response->json('data', []);

            return array_column($models, 'id');
        } catch (\Exception $e) {
            return [];
        }
    }
}
