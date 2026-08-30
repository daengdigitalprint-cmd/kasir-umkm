<?php

namespace App\Support;

use App\Models\Store;

/**
 * Skema kolom tunggal untuk ekspor produk, template impor, dan pembacaan file impor.
 *
 * Satu sumber kebenaran supaya file hasil ekspor bisa langsung dipakai kembali
 * sebagai file impor (round-trip) tanpa penyesuaian manual.
 */
final class ProductImportSchema
{
    public const COL_NAME = 'nama_produk';

    public const COL_SKU = 'sku';

    public const COL_BARCODE = 'barcode';

    public const COL_CATEGORY = 'kategori';

    public const COL_BRAND = 'merek';

    public const COL_UNIT = 'satuan';

    public const COL_PRICE = 'harga_jual';

    public const COL_COST = 'harga_beli';

    public const COL_STOCK = 'stok';

    public const COL_MIN_STOCK = 'stok_minimum';

    public const COL_TAX = 'pajak_persen';

    public const COL_INVENTORY_TYPE = 'tipe_inventori';

    public const COL_BASE_UNIT = 'satuan_dasar';

    public const COL_TRACK_STOCK = 'lacak_stok';

    public const COL_UNLIMITED_STOCK = 'stok_tanpa_batas';

    public const COL_ALLOWS_DECIMAL = 'izinkan_desimal';

    public const COL_IS_ACTIVE = 'status_aktif';

    public const COL_DESCRIPTION = 'deskripsi';

    public const COL_IMAGE_URL = 'url_gambar';

    public const COL_BATCH_CODE = 'kode_batch';

    public const COL_EXPIRY_DATE = 'tanggal_kedaluwarsa';

    public const COL_PHARMACY_CLASS = 'kelas_penjualan_obat';

    public const COL_PHARMACY_REG_NO = 'no_registrasi_obat';

    /** Kolom informasi pada file ekspor; diabaikan saat impor. */
    public const EXPORT_INFO_COLUMNS = [
        'id_produk' => 'ID Produk',
        'punya_varian' => 'Punya Varian (info)',
        'produk_paket' => 'Produk Paket (info)',
        'rata_rata_hpp' => 'Rata-rata HPP (info)',
    ];

    /**
     * Alias header yang tetap dikenali supaya pengguna tidak gagal impor karena beda penamaan.
     *
     * @var array<string, string>
     */
    private const HEADER_ALIASES = [
        'nama' => self::COL_NAME,
        'nama_barang' => self::COL_NAME,
        'produk' => self::COL_NAME,
        'kode' => self::COL_SKU,
        'kode_produk' => self::COL_SKU,
        'kode_barcode' => self::COL_BARCODE,
        'kategori_produk' => self::COL_CATEGORY,
        'brand' => self::COL_BRAND,
        'satuan_jual' => self::COL_UNIT,
        'harga' => self::COL_PRICE,
        'harga_satuan' => self::COL_PRICE,
        'harga_pokok' => self::COL_COST,
        'hpp' => self::COL_COST,
        'stok_awal' => self::COL_STOCK,
        'jumlah_stok' => self::COL_STOCK,
        'stok_min' => self::COL_MIN_STOCK,
        'minimum_stok' => self::COL_MIN_STOCK,
        'pajak' => self::COL_TAX,
        'tax_rate' => self::COL_TAX,
        'tipe' => self::COL_INVENTORY_TYPE,
        'tipe_produk' => self::COL_INVENTORY_TYPE,
        'aktif' => self::COL_IS_ACTIVE,
        'status' => self::COL_IS_ACTIVE,
        'ed' => self::COL_EXPIRY_DATE,
        'expired' => self::COL_EXPIRY_DATE,
        'kadaluarsa' => self::COL_EXPIRY_DATE,
        'batch' => self::COL_BATCH_CODE,
        'golongan_obat' => self::COL_PHARMACY_CLASS,
        'kelas_obat' => self::COL_PHARMACY_CLASS,
        'no_registrasi' => self::COL_PHARMACY_REG_NO,
        'nomor_registrasi' => self::COL_PHARMACY_REG_NO,
        'gambar' => self::COL_IMAGE_URL,
        'foto' => self::COL_IMAGE_URL,
        'image' => self::COL_IMAGE_URL,
        'foto_produk' => self::COL_IMAGE_URL,
    ];

    /**
     * Definisi kolom impor untuk sebuah toko (kolom batch & apotek mengikuti pengaturan toko).
     *
     * @return list<array{key: string, label: string, width: int, guide: string, examples: array{0: string, 1: string}}>
     */
    public static function columnsFor(Store $store): array
    {
        $columns = [
            [
                'key' => self::COL_NAME,
                'label' => 'Nama Produk*',
                'width' => 30,
                'guide' => 'Wajib diisi. Maksimal 255 karakter. Dipakai sebagai pencocokan terakhir bila SKU & barcode kosong.',
                'examples' => ['Es Teh Manis', 'Gula Pasir'],
            ],
            [
                'key' => self::COL_SKU,
                'label' => 'SKU',
                'width' => 18,
                'guide' => 'Opsional, unik per toko. Bila SKU sudah ada, baris tersebut memperbarui produk lama.',
                'examples' => ['MIN-001', 'BHN-001'],
            ],
            [
                'key' => self::COL_BARCODE,
                'label' => 'Barcode',
                'width' => 18,
                'guide' => 'Opsional, unik per toko. Prioritas pencocokan kedua setelah SKU.',
                'examples' => ['8991234567890', ''],
            ],
            [
                'key' => self::COL_CATEGORY,
                'label' => 'Kategori',
                'width' => 20,
                'guide' => 'Opsional. Kategori yang belum ada akan dibuat otomatis untuk toko ini.',
                'examples' => ['Minuman', 'Bahan Baku'],
            ],
            [
                'key' => self::COL_BRAND,
                'label' => 'Merek',
                'width' => 18,
                'guide' => 'Opsional. Maksimal 255 karakter.',
                'examples' => ['', 'Gulaku'],
            ],
            [
                'key' => self::COL_UNIT,
                'label' => 'Satuan',
                'width' => 12,
                'guide' => 'Satuan tampilan pada struk/POS, contoh: pcs, cup, botol, kg. Kosong = pcs.',
                'examples' => ['cup', 'kg'],
            ],
            [
                'key' => self::COL_PRICE,
                'label' => 'Harga Jual',
                'width' => 15,
                'guide' => 'Opsional, angka >= 0. Kosong = 0.',
                'examples' => ['5000', '18000'],
            ],
            [
                'key' => self::COL_COST,
                'label' => 'Harga Beli',
                'width' => 15,
                'guide' => 'Opsional, angka >= 0. Dipakai sebagai HPP awal produk baru.',
                'examples' => ['2500', '15000'],
            ],
            [
                'key' => self::COL_STOCK,
                'label' => 'Stok',
                'width' => 12,
                'guide' => 'Angka >= 0. Kosong = 0 (produk baru) atau tetap (pembaruan). Perubahan stok tercatat di riwayat stok.',
                'examples' => ['24', '10'],
            ],
            [
                'key' => self::COL_MIN_STOCK,
                'label' => 'Stok Minimum',
                'width' => 14,
                'guide' => 'Batas peringatan stok rendah. Angka >= 0, kosong = 0.',
                'examples' => ['5', '2'],
            ],
            [
                'key' => self::COL_TAX,
                'label' => 'Pajak (%)',
                'width' => 12,
                'guide' => 'Persen pajak produk, 0 sampai 100. Kosong = 0.',
                'examples' => ['0', '0'],
            ],
            [
                'key' => self::COL_INVENTORY_TYPE,
                'label' => 'Tipe Inventori',
                'width' => 20,
                'guide' => 'Pilihan: '.self::inventoryTypeChoices().'. Kosong = produk jual.',
                'examples' => ['sellable', 'raw_material'],
            ],
            [
                'key' => self::COL_BASE_UNIT,
                'label' => 'Satuan Dasar',
                'width' => 16,
                'guide' => 'Pilihan: '.self::baseUnitChoices().'. Kosong = pcs. Tidak bisa diubah jika produk lama masih punya stok.',
                'examples' => ['pcs', 'gram'],
            ],
            [
                'key' => self::COL_TRACK_STOCK,
                'label' => 'Lacak Stok',
                'width' => 14,
                'guide' => 'ya / tidak. Kosong = ya. Pilih tidak untuk jasa atau produk tanpa stok.',
                'examples' => ['ya', 'ya'],
            ],
            [
                'key' => self::COL_UNLIMITED_STOCK,
                'label' => 'Stok Tanpa Batas',
                'width' => 18,
                'guide' => 'ya / tidak. Kosong = tidak. Jika ya, stok tidak dikurangi saat penjualan.',
                'examples' => ['tidak', 'tidak'],
            ],
            [
                'key' => self::COL_ALLOWS_DECIMAL,
                'label' => 'Izinkan Desimal',
                'width' => 18,
                'guide' => 'ya / tidak. Kosong = tidak. Untuk produk timbangan (0,5 kg). Hanya tersedia pada paket GrowUp ke atas.',
                'examples' => ['tidak', 'ya'],
            ],
            [
                'key' => self::COL_IS_ACTIVE,
                'label' => 'Status Aktif',
                'width' => 14,
                'guide' => 'ya / tidak. Kosong = ya. Produk tidak aktif disembunyikan dari kasir.',
                'examples' => ['ya', 'ya'],
            ],
            [
                'key' => self::COL_DESCRIPTION,
                'label' => 'Deskripsi',
                'width' => 32,
                'guide' => 'Opsional, catatan bebas tentang produk.',
                'examples' => ['Teh manis dingin 250 ml', ''],
            ],
            [
                'key' => self::COL_IMAGE_URL,
                'label' => 'URL Gambar',
                'width' => 32,
                'guide' => 'Opsional. Alamat http/https gambar produk. Bila diisi, gambar diunduh dan disimpan sebagai gambar produk.',
                'examples' => ['https://contoh.id/gambar.jpg', ''],
            ],
        ];

        if (BatchTracking::enabled($store)) {
            $columns[] = [
                'key' => self::COL_BATCH_CODE,
                'label' => 'Kode Batch',
                'width' => 16,
                'guide' => 'Hanya untuk produk baru saat mode batch aktif. Kode batch stok awal.',
                'examples' => ['B-2601', ''],
            ];
            $columns[] = [
                'key' => self::COL_EXPIRY_DATE,
                'label' => 'Tanggal Kedaluwarsa',
                'width' => 20,
                'guide' => 'Format YYYY-MM-DD. Wajib untuk apotek bila stok awal lebih dari 0.',
                'examples' => ['2027-12-31', ''],
            ];
        }

        if (PharmacyFeatures::enabled($store)) {
            $columns[] = [
                'key' => self::COL_PHARMACY_CLASS,
                'label' => 'Kelas Penjualan Obat*',
                'width' => 22,
                'guide' => 'Wajib untuk apotek. Pilihan: otc (bebas), limited (bebas terbatas), prescription (resep).',
                'examples' => ['otc', 'otc'],
            ];
            $columns[] = [
                'key' => self::COL_PHARMACY_REG_NO,
                'label' => 'No. Registrasi Obat',
                'width' => 22,
                'guide' => 'Opsional, maksimal 128 karakter (contoh nomor izin edar BPOM).',
                'examples' => ['DBL1234567890A1', ''],
            ];
        }

        return $columns;
    }

    /**
     * Label header baris pertama.
     *
     * @return list<string>
     */
    public static function headingsFor(Store $store, bool $withExportInfo = false): array
    {
        $labels = array_map(
            static fn (array $col): string => $col['label'],
            self::columnsFor($store),
        );

        if ($withExportInfo) {
            foreach (self::EXPORT_INFO_COLUMNS as $label) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    public static function keysFor(Store $store): array
    {
        return array_map(
            static fn (array $col): string => $col['key'],
            self::columnsFor($store),
        );
    }

    /** Normalisasi header agar toleran terhadap spasi, huruf besar, tanda bintang, dan alias. */
    public static function normalizeHeader(mixed $raw): string
    {
        $value = is_scalar($raw) ? (string) $raw : '';
        $value = mb_strtolower(trim($value));
        $value = str_replace(['%', '(', ')', '.', '/'], ' ', $value);
        $value = (string) preg_replace('/[^a-z0-9]+/u', '_', $value);
        $value = trim($value, '_');

        if ($value === '') {
            return '';
        }

        return self::HEADER_ALIASES[$value] ?? $value;
    }

    /**
     * Interpretasi nilai boolean dari spreadsheet (ya/tidak, true/false, 1/0, y/n).
     */
    public static function parseBoolean(mixed $raw, bool $default): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return $default;
        }
        if (is_int($raw) || is_float($raw)) {
            return ((float) $raw) != 0.0;
        }

        $value = mb_strtolower(trim((string) $raw));

        if (in_array($value, ['ya', 'y', 'yes', 'true', '1', 'aktif', 'benar', 'ada'], true)) {
            return true;
        }
        if (in_array($value, ['tidak', 'n', 'no', 'false', '0', 'nonaktif', 'non aktif', 'salah', '-'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Angka gaya Indonesia maupun internasional: "Rp 10.000", "10000", "1.234,56", "10.5".
     */
    public static function parseNumber(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        if (! is_string($raw)) {
            return null;
        }

        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        $value = (string) preg_replace('/(rp|idr)/i', '', $value);
        $value = (string) preg_replace('/[\s\x{00A0}]/u', '', $value);

        if ($value === '' || ! preg_match('/^-?[0-9.,]+$/', $value)) {
            return null;
        }

        $hasDot = str_contains($value, '.');
        $hasComma = str_contains($value, ',');

        if ($hasDot && $hasComma) {
            // Format Indonesia: titik ribuan, koma desimal.
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif ($hasComma) {
            $value = str_replace(',', '.', $value);
        } elseif ($hasDot) {
            $parts = explode('.', $value);
            $last = end($parts);
            // "10.000" / "1.234.567" dianggap ribuan; "10.5" dianggap desimal.
            if (count($parts) > 1 && strlen($last) === 3 && ! (count($parts) === 2 && strlen($parts[0]) > 3)) {
                $value = str_replace('.', '', $value);
            }
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /** @return array<string, string> label (huruf kecil) => key tipe inventori */
    public static function inventoryTypeMap(): array
    {
        $map = [];
        foreach (InventoryType::LABELS as $key => $label) {
            $map[$key] = $key;
            $map[mb_strtolower($label)] = $key;
        }

        return $map + [
            'produk jual' => InventoryType::SELLABLE,
            'jual' => InventoryType::SELLABLE,
            'pos' => InventoryType::SELLABLE,
            'bahan baku' => InventoryType::RAW_MATERIAL,
            'bahan' => InventoryType::RAW_MATERIAL,
            'bahan mentah' => InventoryType::RAW_MATERIAL,
            'kemasan' => InventoryType::PACKAGING,
            'hasil produksi' => InventoryType::PRODUCTION_OUTPUT,
        ];
    }

    /** @return array<string, string> label (huruf kecil) => key satuan dasar */
    public static function baseUnitMap(): array
    {
        $map = [];
        foreach (BaseUnit::LABELS as $key => $label) {
            $map[$key] = $key;
            $map[mb_strtolower($label)] = $key;
        }

        return $map + [
            'satuan' => BaseUnit::PIECE,
            'buah' => BaseUnit::PIECE,
            'piece' => BaseUnit::PIECE,
            'berat' => BaseUnit::GRAM,
            'g' => BaseUnit::GRAM,
            'mililiter' => BaseUnit::MILLILITER,
            'volume' => BaseUnit::MILLILITER,
        ];
    }

    /** @return array<string, string> */
    public static function pharmacyClassMap(): array
    {
        return [
            'otc' => 'otc',
            'bebas' => 'otc',
            'obat bebas' => 'otc',
            'limited' => 'limited',
            'terbatas' => 'limited',
            'bebas terbatas' => 'limited',
            'obat bebas terbatas' => 'limited',
            'prescription' => 'prescription',
            'resep' => 'prescription',
            'obat keras' => 'prescription',
        ];
    }

    public static function inventoryTypeChoices(): string
    {
        return implode(', ', array_keys(InventoryType::LABELS));
    }

    public static function baseUnitChoices(): string
    {
        return implode(', ', array_keys(BaseUnit::LABELS));
    }

    public static function booleanLabel(mixed $value): string
    {
        return $value ? 'ya' : 'tidak';
    }

    /**
     * Baris panduan pengisian untuk sheet "Panduan" pada template.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function guideRowsFor(Store $store): array
    {
        $rows = [];
        foreach (self::columnsFor($store) as $col) {
            $rows[] = [$col['label'], $col['guide']];
        }

        return $rows;
    }

    /**
     * Catatan umum aturan impor.
     *
     * @return list<string>
     */
    public static function generalNotesFor(Store $store): array
    {
        $notes = [
            'Isi data mulai baris ke-2 pada sheet "Template Produk". Jangan mengubah atau menghapus baris header.',
            'Kolom bertanda * wajib diisi untuk produk baru (Harga Jual opsional; kosong = 0).',
            'Baris dicocokkan ke produk lama berdasarkan SKU, lalu Barcode, lalu Nama Produk. Bila tidak ada yang cocok, produk baru dibuat.',
            'Saat memperbarui produk, kolom yang dibiarkan kosong akan mempertahankan nilai yang tersimpan di sistem.',
            'Kolom pilihan ya/tidak menerima: ya, tidak, y, n, true, false, 1, 0.',
            'Angka boleh ditulis 10000 atau 10.000. Gunakan koma untuk desimal, contoh 0,5.',
            'Bila ada satu baris yang tidak valid, seluruh proses impor dibatalkan sehingga data lama tetap utuh.',
            'Produk bervarian dan produk paket tidak dapat diimpor melalui file ini; kelola melalui halaman Tambah/Edit Produk.',
            'Urutan impor otomatis: bahan baku, kemasan, dan hasil produksi diimpor terlebih dahulu sebelum produk jual. Anda tidak perlu mengurutkan baris secara manual.',
            'Maksimal 2.000 baris data per file impor.',
        ];

        if (BatchTracking::enabled($store)) {
            $notes[] = 'Mode batch & ED aktif: kode batch dan tanggal kedaluwarsa hanya dipakai saat pembuatan produk baru. Stok produk lama harus disesuaikan lewat halaman produk agar data batch tidak rusak.';
        }

        if (PharmacyFeatures::enabled($store)) {
            $notes[] = 'Mode apotek aktif: kolom Kelas Penjualan Obat wajib diisi dan tanggal kedaluwarsa wajib ada bila stok awal lebih dari 0.';
        }

        return $notes;
    }
}
