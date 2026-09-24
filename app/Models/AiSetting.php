<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Nilai setting berdasarkan key (null jika belum ada).
     */
    public static function get(string $key): ?string
    {
        $row = static::query()->where('key', $key)->first();

        return $row?->value;
    }

    /**
     * Simpan / perbarui nilai setting berdasarkan key.
     */
    public static function set(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }

    /**
     * Hapus setting berdasarkan key.
     */
    public static function remove(string $key): void
    {
        static::query()->where('key', $key)->delete();
    }

    /**
     * Provider AI aktif: dari database, fallback ke config default.
     */
    public static function activeProvider(): string
    {
        $provider = static::get('provider');

        if ($provider !== null && $provider !== '') {
            return $provider;
        }

        return (string) config('ai.default_provider', 'openai');
    }

    /**
     * API key aktif: dari database, fallback ke config dari .env.
     */
    public static function apiKey(): ?string
    {
        $key = static::get('api_key');

        if ($key !== null && $key !== '') {
            return $key;
        }

        $provider = static::activeProvider();

        $envKey = config("ai.providers.{$provider}.key");

        return is_string($envKey) && $envKey !== '' ? $envKey : null;
    }

    /**
     * Terapkan provider & API key dari database ke config runtime
     * agar seluruh jalur AI (chat, auditor, analisa) memakainya.
     */
    public static function applyToRuntime(): string
    {
        $provider = static::activeProvider();

        config(['ai.default_provider' => $provider]);

        $key = static::apiKey();

        if ($key !== null) {
            config(["ai.providers.{$provider}.key" => $key]);
            config(["ai.providers.{$provider}.api_key" => $key]);
        }

        $url = static::get('url');

        if ($url !== null) {
            $baseUrl = rtrim((string) preg_replace('#/chat/completions$#', '', $url), '/');

            config(["ai.providers.{$provider}.url" => $baseUrl]);
            config(["ai.providers.{$provider}.base_url" => $baseUrl]);
        } elseif (! config()->has("ai.providers.{$provider}.base_url")) {
            $defaultBase = static::defaultBaseUrls()[$provider] ?? null;

            if ($defaultBase !== null) {
                config(["ai.providers.{$provider}.url" => $defaultBase]);
                config(["ai.providers.{$provider}.base_url" => $defaultBase]);
            }
        }

        $model = static::get('model');

        if ($model !== null) {
            config(["ai.providers.{$provider}.model" => $model]);
            config(["ai.providers.{$provider}.model_id" => $model]);
        }

        return $provider;
    }

    /**
     * Base URL default untuk provider yang belum terdaftar di config/ai.php.
     *
     * @return array<string, string>
     */
    private static function defaultBaseUrls(): array
    {
        return [
            'groq' => 'https://api.groq.com/openai/v1',
            'deepseek' => 'https://api.deepseek.com/v1',
            'mistral' => 'https://api.mistral.ai/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
            'xai' => 'https://api.x.ai/v1',
            'ollama' => 'http://localhost:11434/v1',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'custom' => 'https://api.openai.com/v1',
        ];
    }

    /**
     * Masking API key untuk ditampilkan (mis. "sk-pro...9f2a").
     */
    public static function maskedKey(): ?string
    {
        $key = static::apiKey();

        if ($key === null) {
            return null;
        }

        $length = strlen($key);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($key, 0, 6).'...'.substr($key, -4);
    }
}
