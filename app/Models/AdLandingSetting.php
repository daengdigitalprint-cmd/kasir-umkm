<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan landing page iklan (/mulai), diisi dari Super Admin > Lead Iklan.
 *
 * Nilai dari panel admin didahulukan; bila kosong, dipakai nilai .env
 * (config/services.php) supaya instalasi lama tetap berjalan.
 */
class AdLandingSetting extends Model
{
    protected $fillable = [
        'meta_pixel_id',
        'meta_capi_token',
        'meta_test_event_code',
        'offer_text',
    ];

    protected $hidden = ['meta_capi_token'];

    protected function casts(): array
    {
        return [
            // Token akses server-ke-server: jangan tersimpan polos di database.
            'meta_capi_token' => 'encrypted',
        ];
    }

    /** Satu baris pengaturan; dibuat saat pertama kali disimpan. */
    public static function current(): self
    {
        return static::query()->first() ?? new self;
    }

    public static function pixelId(): ?string
    {
        return self::read('meta_pixel_id', 'services.meta.pixel_id');
    }

    public static function capiToken(): ?string
    {
        return self::read('meta_capi_token', 'services.meta.capi_token');
    }

    public static function testEventCode(): ?string
    {
        return self::read('meta_test_event_code', 'services.meta.test_event_code');
    }

    public static function offerText(): ?string
    {
        return self::read('offer_text', null);
    }

    /**
     * Terima ID Pixel polos maupun potongan kode Pixel utuh yang ditempel
     * dari Events Manager (diambil angka di dalam fbq('init', '...')).
     * null bila tidak ditemukan ID yang masuk akal.
     */
    public static function extractPixelId(?string $input): ?string
    {
        $input = trim((string) $input);

        if ($input === '') {
            return null;
        }

        if (preg_match('/fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"](\d{8,20})[\'"]/', $input, $match) === 1) {
            return $match[1];
        }

        if (preg_match('/facebook\.com\/tr\?id=(\d{8,20})/', $input, $match) === 1) {
            return $match[1];
        }

        $digits = preg_replace('/\s+/', '', $input) ?? '';

        return preg_match('/^\d{8,20}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * Landing iklan tidak boleh tumbang karena pengaturan pelacakan: bila
     * tabel belum ada (migrasi belum jalan) atau token tidak bisa didekripsi
     * (APP_KEY berganti), anggap belum diatur dan pakai nilai .env.
     */
    private static function read(string $column, ?string $configKey): ?string
    {
        try {
            $value = static::query()->first()?->{$column};
        } catch (\Throwable) {
            $value = null;
        }

        if (filled($value)) {
            return (string) $value;
        }

        $fallback = $configKey !== null ? config($configKey) : null;

        return filled($fallback) ? (string) $fallback : null;
    }
}
