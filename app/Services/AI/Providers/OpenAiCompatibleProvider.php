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

    /**
     * Sebagian model baru (mis. Claude lewat lapisan OpenAI-compatible)
     * menolak parameter `temperature`. Begitu ditolak, parameter itu
     * dilepas dan permintaan diulang sekali.
     */
    protected bool $sendTemperature = true;

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

    /**
     * Susun payload permintaan. `temperature` ikut hanya bila model
     * belum pernah menolaknya.
     *
     * @param  array<int, mixed>  $messages
     * @param  array<int, mixed>  $tools
     * @return array<string, mixed>
     */
    protected function buildPayload(array $messages, array $tools, bool $stream = false): array
    {
        $payload = [
            'model' => $this->modelId,
            'messages' => $messages,
            'max_tokens' => $this->maxTokens,
        ];

        if ($this->sendTemperature) {
            $payload['temperature'] = $this->temperature;
        }

        if ($stream) {
            $payload['stream'] = true;
        }

        if (! empty($tools) && $this->supportsFunctionCalling) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    /** Apakah permintaan ditolak khusus karena parameter `temperature`? */
    protected function rejectsTemperature(int $status, string $body): bool
    {
        if ($status !== 400 || ! $this->sendTemperature) {
            return false;
        }

        $body = strtolower($body);

        return str_contains($body, 'temperature')
            && (str_contains($body, 'deprecated')
                || str_contains($body, 'unsupported')
                || str_contains($body, 'not supported')
                || str_contains($body, 'does not support'));
    }

    /**
     * Ambil pesan error yang dikirim provider (field error.message),
     * supaya admin tahu penyebab aslinya alih-alih pesan umum.
     */
    protected function providerMessage(string $body): ?string
    {
        $data = json_decode($body, true);

        if (! is_array($data)) {
            return null;
        }

        $message = $data['error']['message'] ?? $data['message'] ?? null;

        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        return \Illuminate\Support\Str::limit(trim($message), 160);
    }

    public function chat(array $messages, array $tools = []): AiResponse
    {
        try {
            $timeout = (int) config('ai.timeout', 45);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout($timeout)
                ->post("{$this->baseUrl}/chat/completions", $this->buildPayload($messages, $tools));

            // Model yang menolak `temperature`: lepas parameternya, ulang sekali.
            if ($response->failed() && $this->rejectsTemperature($response->status(), $response->body())) {
                $this->sendTemperature = false;

                Log::info('AI Provider: `temperature` ditolak model, mengulang tanpa parameter itu', [
                    'model' => $this->modelId,
                ]);

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                    ->timeout($timeout)
                    ->post("{$this->baseUrl}/chat/completions", $this->buildPayload($messages, $tools));
            }

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
            $timeout = (int) config('ai.timeout', 45);

            $send = fn () => Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
            ])
                ->withOptions(['stream' => true])
                ->timeout($timeout)
                ->post("{$this->baseUrl}/chat/completions", $this->buildPayload($messages, $tools, stream: true));

            $response = $send();
            $stream = $response->toPsrResponse()->getBody();

            // Body aliran hanya bisa dibaca sekali, jadi disimpan saat dibaca.
            $body = $response->failed() ? $stream->getContents() : null;

            // Model yang menolak `temperature`: lepas parameternya, ulang sekali.
            if ($response->failed() && $this->rejectsTemperature($response->status(), (string) $body)) {
                $this->sendTemperature = false;

                Log::info('AI Provider: `temperature` ditolak model (stream), mengulang tanpa parameter itu', [
                    'model' => $this->modelId,
                ]);

                $response = $send();
                $stream = $response->toPsrResponse()->getBody();
                $body = $response->failed() ? $stream->getContents() : null;
            }

            if ($response->failed()) {
                Log::error('AI Provider Error (stream)', [
                    'status' => $response->status(),
                    'body' => (string) $body,
                ]);

                return AiResponse::error($this->friendlyApiError($response->status(), (string) $body));
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
        // Keterangan asli provider jauh lebih berguna untuk menelusuri masalah
        // (mis. "model sedang maintenance") daripada pesan umum saja.
        $detail = $this->providerMessage($body);
        $suffix = $detail !== null ? ' (keterangan provider: '.$detail.')' : '';

        if ($status === 401 || $status === 403) {
            return 'Kunci API AI tidak valid atau kedaluwarsa. Hubungi admin untuk perbarui pengaturan AI.'.$suffix;
        }

        if ($status === 429) {
            return 'Batas pemakaian AI tercapai. Silakan coba lagi beberapa menit kemudian.'.$suffix;
        }

        if ($status >= 500) {
            return 'Provider AI sedang gangguan. Silakan coba lagi beberapa saat.'.$suffix;
        }

        return 'Provider AI menolak permintaan. Silakan coba lagi atau hubungi admin jika berlanjut.'.$suffix;
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
