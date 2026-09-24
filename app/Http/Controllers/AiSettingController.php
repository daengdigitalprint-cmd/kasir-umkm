<?php

namespace App\Http\Controllers;

use App\Http\Requests\SuperAdmin\AiSettingUpdateRequest;
use App\Models\AiSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class AiSettingController extends Controller
{
    /**
     * Label tampilan untuk setiap provider AI yang didukung.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function providerOptions(): array
    {
        return [
            ['value' => 'openai', 'label' => 'OpenAI (GPT-4o / GPT-4o mini)'],
            ['value' => 'anthropic', 'label' => 'Anthropic (Claude)'],
            ['value' => 'gemini', 'label' => 'Google Gemini'],
            ['value' => 'groq', 'label' => 'Groq'],
            ['value' => 'deepseek', 'label' => 'DeepSeek'],
            ['value' => 'mistral', 'label' => 'Mistral AI'],
            ['value' => 'openrouter', 'label' => 'OpenRouter'],
            ['value' => 'xai', 'label' => 'xAI (Grok)'],
            ['value' => 'ollama', 'label' => 'Ollama (Lokal / Offline)'],
            ['value' => 'custom', 'label' => 'Custom API (OpenAI Compatible)'],
        ];
    }

    /**
     * URL default untuk setiap provider yang didukung.
     *
     * @return array<string, string>
     */
    private function providerUrls(): array
    {
        return [
            'openai' => 'https://api.openai.com/v1/chat/completions',
            'anthropic' => 'https://api.anthropic.com/v1/messages',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'groq' => 'https://api.groq.com/openai/v1/chat/completions',
            'deepseek' => 'https://api.deepseek.com/v1/chat/completions',
            'mistral' => 'https://api.mistral.ai/v1/chat/completions',
            'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
            'xai' => 'https://api.x.ai/v1/chat/completions',
            'ollama' => 'http://localhost:11434/v1/chat/completions',
        ];
    }

    /**
     * Halaman pengaturan AI (Super Admin).
     */
    public function index(): Response
    {
        return Inertia::render('AiSetting/Index', [
            'providers' => $this->providerOptions(),
            'activeProvider' => AiSetting::activeProvider(),
            'maskedKey' => AiSetting::maskedKey(),
            'hasApiKey' => AiSetting::apiKey() !== null,
            'source' => AiSetting::get('api_key') !== null ? 'database' : 'env',
            'url' => AiSetting::get('url'),
            'model' => AiSetting::get('model'),
        ]);
    }

    /**
     * Simpan provider & API key AI dari halaman Super Admin.
     */
    public function update(AiSettingUpdateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $apiKey = $validated['api_key'] ?? null;
        $url = $validated['url'] ?? null;
        $model = $validated['model'] ?? null;

        // Key kosong = pertahankan key yang tersimpan (hanya ganti provider).
        if ($apiKey === null || $apiKey === '') {
            $existingKey = AiSetting::get('api_key');

            AiSetting::set('provider', $validated['provider']);

            if ($existingKey === null) {
                // Belum ada key tersimpan: ambil dari .env provider baru bila ada.
                $envKey = config("ai.providers.{$validated['provider']}.key");

                if (is_string($envKey) && $envKey !== '') {
                    AiSetting::set('api_key', $envKey);
                }
            }
        } else {
            AiSetting::set('provider', $validated['provider']);
            AiSetting::set('api_key', $apiKey);
        }

        // Simpan URL kustom jika provider adalah 'custom'
        if ($validated['provider'] === 'custom' && $url) {
            AiSetting::set('url', $url);
        } elseif ($validated['provider'] !== 'custom') {
            AiSetting::remove('url');
        }

        // Simpan atau hapus model
        if ($model) {
            AiSetting::set('model', $model);
        } else {
            AiSetting::remove('model');
        }

        return response()->json([
            'success' => true,
            'message' => 'Pengaturan AI berhasil disimpan.',
            'activeProvider' => AiSetting::activeProvider(),
            'maskedKey' => AiSetting::maskedKey(),
            'hasApiKey' => AiSetting::apiKey() !== null,
            'source' => AiSetting::get('api_key') !== null ? 'database' : 'env',
        ]);
    }

    /**
     * Uji koneksi ke provider AI berdasarkan konfigurasi yang akan disimpan / aktif.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $request->validate([
            'provider' => ['required', 'string', 'in:openai,anthropic,gemini,groq,deepseek,mistral,openrouter,xai,ollama,custom'],
            'api_key' => ['nullable', 'string'],
            'url' => ['nullable', 'string'],
            'model' => ['nullable', 'string'],
        ]);

        $provider = $request->input('provider');
        $apiKey = $request->input('api_key') ?: AiSetting::get('api_key') ?: config("ai.providers.{$provider}.key");
        $url = $request->input('url') ?: AiSetting::get('url');
        $model = $request->input('model') ?: AiSetting::get('model');

        if (! $apiKey || $apiKey === '') {
            return response()->json([
                'success' => false,
                'message' => 'API Key belum dikonfigurasi. Masukkan API Key terlebih dahulu.',
            ], 422);
        }

        try {
            $result = $this->sendTestRequest($provider, $apiKey, $url, $model);

            return response()->json([
                'success' => true,
                'message' => 'Koneksi berhasil! Provider '.$provider.' merespons dengan baik.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal terhubung ke AI. Periksa kembali API Key, URL, atau Model Anda. Error: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Kirim request test ke provider AI berdasarkan tipe provider.
     *
     * @throws \Exception
     */
    private function sendTestRequest(string $provider, string $apiKey, ?string $url, ?string $model): string
    {
        return match ($provider) {
            'openai', 'groq', 'deepseek', 'mistral', 'openrouter', 'xai', 'ollama' => $this->testOpenAiCompatible($provider, $apiKey, $url, $model),
            'anthropic' => $this->testAnthropic($apiKey, $model),
            'gemini' => $this->testGemini($apiKey, $model),
            'custom' => $this->testCustom($apiKey, $url, $model),
            default => throw new \Exception('Provider "'.$provider.'" belum didukung untuk test koneksi.'),
        };
    }

    /**
     * Test koneksi ke provider yang kompatibel dengan OpenAI API format.
     *
     * @throws \Exception
     */
    private function testOpenAiCompatible(string $provider, string $apiKey, ?string $url, ?string $model): string
    {
        $defaultUrls = $this->providerUrls();
        $endpoint = $url ?: ($defaultUrls[$provider] ?? null);

        if (! $endpoint) {
            throw new \Exception('URL endpoint untuk provider "'.$provider.'" tidak diketahui. Isi URL secara manual.');
        }

        $defaultModels = [
            'openai' => 'gpt-4o-mini',
            'groq' => 'llama-3.1-8b-instant',
            'deepseek' => 'deepseek-chat',
            'mistral' => 'mistral-small-latest',
            'openrouter' => 'openai/gpt-4o-mini',
            'xai' => 'grok-2-1212',
            'ollama' => 'llama3.2',
        ];

        $modelId = $model ?: ($defaultModels[$provider] ?? 'gpt-4o-mini');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post($endpoint, [
            'model' => $modelId,
            'messages' => [
                ['role' => 'user', 'content' => 'Hi, testing connection. Reply with "OK" only.'],
            ],
            'max_tokens' => 10,
        ]);

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new \Exception('HTTP '.$response->status().': '.mb_substr((string) $error, 0, 200));
        }

        return $response->body();
    }

    /**
     * Test koneksi ke Anthropic API.
     *
     * @throws \Exception
     */
    private function testAnthropic(string $apiKey, ?string $model): string
    {
        $modelId = $model ?: 'claude-3-5-haiku-20241022';
        $endpoint = 'https://api.anthropic.com/v1/messages';

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(30)->post($endpoint, [
            'model' => $modelId,
            'max_tokens' => 10,
            'messages' => [
                ['role' => 'user', 'content' => 'Hi, testing connection. Reply with "OK" only.'],
            ],
        ]);

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new \Exception('HTTP '.$response->status().': '.mb_substr((string) $error, 0, 200));
        }

        return $response->body();
    }

    /**
     * Test koneksi ke Google Gemini API.
     *
     * @throws \Exception
     */
    private function testGemini(string $apiKey, ?string $model): string
    {
        $modelId = $model ?: 'gemini-1.5-flash';
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'.$modelId.':generateContent?key='.$apiKey;

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->timeout(30)->post($endpoint, [
            'contents' => [
                ['parts' => [['text' => 'Hi, testing connection. Reply with "OK" only.']]],
            ],
            'generationConfig' => ['maxOutputTokens' => 10],
        ]);

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new \Exception('HTTP '.$response->status().': '.mb_substr((string) $error, 0, 200));
        }

        return $response->body();
    }

    /**
     * Test koneksi ke Custom API (OpenAI compatible).
     *
     * @throws \Exception
     */
    private function testCustom(string $apiKey, ?string $url, ?string $model): string
    {
        if (! $url) {
            throw new \Exception('URL endpoint wajib diisi untuk provider Custom.');
        }

        $endpoint = rtrim($url, '/').'/chat/completions';
        $modelId = $model ?: 'default';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post($endpoint, [
            'model' => $modelId,
            'messages' => [
                ['role' => 'user', 'content' => 'Hi, testing connection. Reply with "OK" only.'],
            ],
            'max_tokens' => 10,
        ]);

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new \Exception('HTTP '.$response->status().': '.mb_substr((string) $error, 0, 200));
        }

        return $response->body();
    }

    /**
     * Hapus API key yang tersimpan di database (kembali ke .env bila ada).
     */
    public function destroy(Request $request): JsonResponse
    {
        AiSetting::remove('api_key');

        return response()->json([
            'success' => true,
            'message' => 'API Key berhasil dihapus dari pengaturan.',
            'activeProvider' => AiSetting::activeProvider(),
            'maskedKey' => AiSetting::maskedKey(),
            'hasApiKey' => AiSetting::apiKey() !== null,
            'source' => AiSetting::get('api_key') !== null ? 'database' : 'env',
        ]);
    }
}
