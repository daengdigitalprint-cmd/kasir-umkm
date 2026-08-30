<?php

namespace App\Support;

use App\Models\Store;

class ReceiptSettings
{
    /** URL path sama origin (hindari salah host jika APP_URL beda dengan domain akses, mis. Herd). */
    private static function publicStoragePath(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        return '/storage/'.ltrim(str_replace('\\', '/', $relativePath), '/');
    }

    /**
     * @return array{
     *     header_name: string,
     *     subtitle: string,
     *     footer: string,
     *     footer_line2: string,
     *     show_address: bool,
     *     show_phone: bool,
     *     show_logo: bool,
     *     logo_url: ?string,
     *     paper_width_mm: int,
     *     address: string,
     *     phone: string,
     * }
     */
    public static function payload(Store $store): array
    {
        $s = $store->settings ?? [];

        $width = (string) ($s['receipt_paper_width'] ?? '80');
        $paperMm = ($width === '58') ? 58 : 80;

        $logoUrl = null;
        $showLogo = (bool) ($s['receipt_show_logo'] ?? true);
        if ($showLogo) {
            if (! empty($store->receipt_logo) && \Illuminate\Support\Facades\Storage::disk('public')->exists($store->receipt_logo)) {
                $logoUrl = self::publicStoragePath($store->receipt_logo);
            } else {
                $legacyUrl = trim((string) ($s['receipt_logo_url'] ?? ''));
                if ($legacyUrl !== '') {
                    $logoUrl = preg_match('#^https?://#i', $legacyUrl) === 1
                        ? $legacyUrl
                        : '/'.ltrim($legacyUrl, '/');
                } elseif (! empty($store->logo)) {
                    $logoUrl = self::publicStoragePath($store->logo);
                }
            }
        }

        $header = trim((string) ($s['receipt_header_name'] ?? ''));
        if ($header === '') {
            $header = $store->name;
        }

        return [
            'header_name' => $header,
            'subtitle' => (string) ($s['receipt_subtitle'] ?? ''),
            'footer' => (string) ($s['receipt_footer'] ?? 'Terima kasih atas kunjungan Anda.'),
            'footer_line2' => (string) ($s['receipt_footer_line2'] ?? ''),
            'show_address' => (bool) ($s['receipt_show_address'] ?? true),
            'show_phone' => (bool) ($s['receipt_show_phone'] ?? true),
            'show_logo' => $showLogo,
            'logo_url' => $logoUrl,
            'paper_width_mm' => $paperMm,
            'address' => (string) ($store->address ?? ''),
            'phone' => (string) ($store->phone ?? ''),
        ];
    }
}
