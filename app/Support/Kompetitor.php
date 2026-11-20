<?php

namespace App\Support;

/**
 * Data kompetitor untuk halaman "Alternatif [kompetitor]" (satu sumber kebenaran).
 *
 * ATURAN (halaman ini adalah klaim publik tentang perusahaan lain):
 * - Hanya tulis yang tercantum di situs resmi mereka, beri tanggal 'checked_at' dan 'source'.
 * - "Tidak tercantum" bukan berarti "tidak ada": tulis "tidak tercantum di situs (per tanggal)".
 * - Jangan menebak alasan/motif mereka. Akui kelebihan mereka.
 * - Periksa ulang setiap 3 bulan atau sebelum mengubah halaman.
 */
final class Kompetitor
{
    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        return self::data()[$key] ?? null;
    }

    /** @return list<string> slug halaman alternatif */
    public static function slugs(): array
    {
        return array_map(fn (array $k) => $k['slug'], array_values(self::data()));
    }

    /** @return array<string, array<string, mixed>> */
    private static function data(): array
    {
        return [
            'loyverse' => [
                'slug' => 'alternatif-loyverse',
                'name' => 'Loyverse',
                'checked_at' => '2026-11-20',
                'checked_label' => '20 November 2026',
                'source' => 'https://loyverse.com/id/pricing',

                // Dari loyverse.com/id/pricing (per 20 Nov 2026). Harga belum termasuk pajak.
                'free' => ['Loyverse POS (kasir, beberapa toko, analisa penjualan, stok, program loyalitas)', 'Loyverse Dashboard', 'Kitchen Display System (KDS)', 'Customer Display System (CDS)'],
                'addons' => [
                    ['name' => 'Riwayat penjualan tanpa batas', 'monthly' => '$9 USD / bulan per toko', 'yearly' => '$90 USD / tahun per toko'],
                    ['name' => 'Manajemen karyawan', 'monthly' => '$5 USD / bulan per karyawan', 'yearly' => '$50 USD / tahun per karyawan'],
                    ['name' => 'Manajemen stok barang (lanjutan)', 'monthly' => '$25 USD / bulan per toko', 'yearly' => '$250 USD / tahun per toko'],
                ],
                'addon_trial' => 'Setiap fasilitas tambahan punya uji coba gratis 14 hari tanpa kartu.',
                // Dari loyverse.com/id/advanced-inventory (per 20 Nov 2026).
                'free_inventory' => 'pelacakan stok real-time, notifikasi stok menipis, item komposit, varian, dan impor item',

                'strengths' => [
                    ['title' => 'Multi toko gratis', 'desc' => 'Mengelola beberapa toko sudah termasuk di Loyverse POS gratis.'],
                    ['title' => 'Layar dapur & layar pelanggan gratis', 'desc' => 'Kitchen Display System dan Customer Display System tercantum gratis.'],
                    // loyverse.com/id/download-loyverse menautkan App Store & Google Play (per 20 Nov 2026).
                    ['title' => 'Aplikasi iPhone dan Android', 'desc' => 'Loyverse POS tersedia di App Store dan Google Play.'],
                    ['title' => 'Produk global yang matang', 'desc' => 'Dipakai di banyak negara, tersedia dalam banyak bahasa, dengan API untuk pengembang.'],
                ],
            ],
        ];
    }
}
