<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiSettingUpdateRequest extends FormRequest
{
    /**
     * Provider AI yang didukung SDK dan bisa diatur dari halaman pengaturan.
     */
    public const SUPPORTED_PROVIDERS = [
        'openai',
        'anthropic',
        'gemini',
        'groq',
        'deepseek',
        'mistral',
        'openrouter',
        'xai',
        'ollama',
    ];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(self::SUPPORTED_PROVIDERS)],
            'api_key' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Pesan error validasi dalam Bahasa Indonesia.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.required' => 'Pilih provider AI terlebih dahulu.',
            'provider.in' => 'Provider AI tidak didukung.',
            'api_key.string' => 'API Key harus berupa teks.',
            'api_key.max' => 'API Key terlalu panjang (maksimal 500 karakter).',
        ];
    }
}
