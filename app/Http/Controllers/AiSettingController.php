<?php

namespace App\Http\Controllers;

use App\Http\Requests\SuperAdmin\AiSettingUpdateRequest;
use App\Models\AiSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        ]);
    }

    /**
     * Simpan provider & API key AI dari halaman Super Admin.
     */
    public function update(AiSettingUpdateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $apiKey = $validated['api_key'] ?? null;

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
