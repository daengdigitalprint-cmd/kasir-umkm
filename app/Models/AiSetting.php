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

        return (string) config('ai.default');
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
     * agar Laravel AI SDK memakainya saat analisa dijalankan.
     */
    public static function applyToRuntime(): string
    {
        $provider = static::activeProvider();

        config(['ai.default' => $provider]);

        $key = static::apiKey();

        if ($key !== null) {
            config(["ai.providers.{$provider}.key" => $key]);
        }

        return $provider;
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
