<?php

namespace App\Support;

/**
 * Daftar layanan contoh untuk onboarding toko barbershop.
 * Dipakai oleh ProductController::sampleServices() sebagai saran katalog awal.
 */
class BarbershopSampleServices
{
    /**
     * @return list<array{name: string, price: int, duration: int, category: string}>
     */
    public static function all(): array
    {
        return [
            ['name' => 'Potong Rambut Pendek', 'price' => 45000, 'duration' => 45, 'category' => 'Layanan Potong'],
            ['name' => 'Potong Rambut Panjang', 'price' => 55000, 'duration' => 60, 'category' => 'Layanan Potong'],
            ['name' => 'Prewash (Cuci & Pijat Kepala)', 'price' => 15000, 'duration' => 15, 'category' => 'Prewash & Styling'],
            ['name' => 'Creambath', 'price' => 35000, 'duration' => 45, 'category' => 'Perawatan Rambut'],
            ['name' => 'Shave (Cukur Jenggot & Kumis)', 'price' => 15000, 'duration' => 15, 'category' => 'Layanan Potong'],
            ['name' => 'Hair Styling / Blow', 'price' => 20000, 'duration' => 20, 'category' => 'Prewash & Styling'],
            ['name' => 'Hair Masker', 'price' => 30000, 'duration' => 30, 'category' => 'Perawatan Rambut'],
            ['name' => 'Paket Potong + Prewash + Creambath', 'price' => 85000, 'duration' => 90, 'category' => 'Paket'],
        ];
    }
}
