<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->isSuperAdmin() || $user->isAdmin() || $user->isManager() || $user->isCashier() || $user->isOwner();
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer', 'exists:ai_conversations,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Pesan tidak boleh kosong',
            'message.max' => 'Pesan maksimal 2000 karakter',
            'conversation_id.exists' => 'Percakapan tidak ditemukan',
        ];
    }
}
