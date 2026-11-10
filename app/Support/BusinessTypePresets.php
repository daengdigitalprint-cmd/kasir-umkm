<?php

namespace App\Support;

/**
 * Preset kategori & satuan per jenis usaha (Fase A — onboarding & form produk).
 */
class BusinessTypePresets
{
    public const TYPES = [
        'general' => 'Umum / Lainnya',
        'retail' => 'Retail / Kelontong',
        'fnb' => 'F&B / Restoran / Kafe',
        'bakery' => 'Roti / Kue / Bakery',
        'pharmacy' => 'Apotek / Kesehatan',
        'laundry' => 'Laundry / Cuci',
        'barbershop' => 'Barbershop / Salon',
    ];

    /**
     * @return array{categories: list<string>, units: list<string>}
     */
    public static function catalog(string $businessType): array
    {
        return match ($businessType) {
            'retail' => [
                'categories' => ['Sembako', 'Minuman', 'Snack', 'Kebutuhan Rumah Tangga', 'Bumbu Dapur', 'Perlengkapan Mandi', 'Lainnya'],
                'units' => ['pcs', 'bungkus', 'dus', 'kaleng', 'sachet', 'liter', 'kg', 'gram'],
            ],
            'fnb' => [
                'categories' => ['Makanan Utama', 'Minuman', 'Camilan', 'Paket', 'Promo', 'Side Dish', 'Lainnya'],
                'units' => ['porsi', 'pcs', 'gelas', 'mangkuk', 'paket'],
            ],
            'bakery' => [
                'categories' => ['Roti Manis', 'Roti Tawar', 'Kue Kering', 'Kue Basah', 'Tart & Custom', 'Lainnya'],
                'units' => ['pcs', 'kotak', 'slice', 'loyang'],
            ],
            'pharmacy' => [
                'categories' => ['Obat Bebas', 'Obat Bebas Terbatas', 'Alat Kesehatan', 'Vitamin & Suplemen', 'Perawatan', 'Lainnya'],
                'units' => ['strip', 'tablet', 'botol', 'tube', 'pcs', 'paket'],
            ],
            'laundry' => [
                'categories' => ['Cuci Kiloan', 'Cuci Satuan', 'Setrika', 'Cuci Sepatu', 'Cuci Karpet & Gorden', 'Dry Clean', 'Lainnya'],
                'units' => ['kg', 'pcs', 'pasang', 'set', 'meter'],
            ],
            'barbershop' => [
                'categories' => ['Layanan Potong', 'Prewash & Styling', 'Perawatan Rambut', 'Paket', 'Produk Retail', 'Lainnya'],
                'units' => ['jasa', 'paket', 'pcs', 'botol'],
            ],
            default => [
                'categories' => ['Umum', 'Lainnya'],
                'units' => ['pcs', 'box', 'paket', 'kg', 'liter'],
            ],
        };
    }

    public static function isValidType(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }
}
