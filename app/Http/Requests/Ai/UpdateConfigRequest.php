<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->isSuperAdmin() || $user->isAdmin();
    }

    public function rules(): array
    {
        return [
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'provider' => ['required', 'string', 'in:openai,anthropic,custom'],
            'base_url' => ['required', 'url', 'max:500'],
            'model_id' => ['required', 'string', 'max:100'],
            'api_key' => ['required', 'string', 'max:1000'],
            'max_tokens' => ['nullable', 'integer', 'min:100', 'max:128000'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider.required' => 'Provider wajib dipilih',
            'provider.in' => 'Provider tidak valid',
            'base_url.required' => 'Base URL wajib diisi',
            'base_url.url' => 'Format URL tidak valid',
            'model_id.required' => 'Model ID wajib diisi',
            'api_key.required' => 'API Key wajib diisi',
            'max_tokens.min' => 'Minimal token adalah 100',
            'max_tokens.max' => 'Maksimal token adalah 128000',
            'temperature.min' => 'Temperature minimal 0',
            'temperature.max' => 'Temperature maksimal 2',
        ];
    }
}
