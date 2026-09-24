<?php

namespace App\Services\AI\Providers;

interface AiProviderInterface
{
    /**
     * Kirim pesan ke AI dan dapatkan respons.
     *
     * @param  array  $messages  Array pesan dalam format OpenAI
     * @param  array  $tools  Array tool definitions (function calling)
     */
    public function chat(array $messages, array $tools = []): AiResponse;

    /**
     * Kirim pesan ke AI dengan respons streaming (SSE).
     * Setiap potongan teks (delta) diteruskan ke callback selama respons dihasilkan.
     *
     * @param  array  $messages  Array pesan dalam format OpenAI
     * @param  array  $tools  Array tool definitions (function calling)
     * @param  callable|null  $onDelta  fn (string $delta): void
     */
    public function chatStream(array $messages, array $tools = [], ?callable $onDelta = null): AiResponse;

    /**
     * Validasi koneksi ke provider.
     */
    public function validateConnection(): bool;

    /**
     * Dapatkan daftar model yang tersedia.
     */
    public function getModels(): array;
}
