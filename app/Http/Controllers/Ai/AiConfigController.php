<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\UpdateConfigRequest;
use App\Models\AiConfiguration;
use App\Services\AI\AiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiConfigController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manage_ai_settings');

        $configs = AiConfiguration::with('store')->get();
        $defaultConfig = config('ai.providers.openai');

        return Inertia::render('Admin/AI/Settings', [
            'configs' => $configs,
            'defaultConfig' => $defaultConfig,
        ]);
    }

    public function store(UpdateConfigRequest $request): JsonResponse
    {
        $this->authorize('manage_ai_settings');

        $config = AiConfiguration::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Konfigurasi AI berhasil disimpan',
            'config' => $config,
        ]);
    }

    public function update(UpdateConfigRequest $request, AiConfiguration $config): JsonResponse
    {
        $this->authorize('manage_ai_settings');

        $config->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Konfigurasi AI berhasil diperbarui',
            'config' => $config,
        ]);
    }

    public function destroy(AiConfiguration $config): JsonResponse
    {
        $this->authorize('manage_ai_settings');

        $config->delete();

        return response()->json([
            'success' => true,
            'message' => 'Konfigurasi AI berhasil dihapus',
        ]);
    }

    public function testConnection(Request $request): JsonResponse
    {
        $this->authorize('manage_ai_settings');

        $request->validate([
            'base_url' => ['required', 'url'],
            'model_id' => ['required', 'string'],
            'api_key' => ['required', 'string'],
        ]);

        $aiService = new AiService;

        // Override provider untuk test
        $provider = new \App\Services\AI\Providers\OpenAiCompatibleProvider([
            'base_url' => $request->base_url,
            'model_id' => $request->model_id,
            'api_key' => $request->api_key,
        ]);

        $isValid = $provider->validateConnection();

        if ($isValid) {
            $models = $provider->getModels();

            return response()->json([
                'success' => true,
                'message' => 'Koneksi berhasil',
                'models' => $models,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Gagal terhubung ke AI provider. Periksa URL dan API key.',
        ], 422);
    }

    public function getModels(Request $request): JsonResponse
    {
        $this->authorize('manage_ai_settings');

        $request->validate([
            'base_url' => ['required', 'url'],
            'api_key' => ['required', 'string'],
        ]);

        $provider = new \App\Services\AI\Providers\OpenAiCompatibleProvider([
            'base_url' => $request->base_url,
            'api_key' => $request->api_key,
        ]);

        $models = $provider->getModels();

        return response()->json([
            'success' => true,
            'models' => $models,
        ]);
    }
}
