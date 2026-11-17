<?php

namespace App\Support;

/**
 * Daftar menu yang hak aksesnya bisa diatur di "Tim & Akses → Peran & Izin".
 *
 * menu_key harus sama dengan `menuKey` di resources/js/components/MainMenu.vue,
 * karena sidebar dan guard rute membaca kunci yang sama lewat User::canAccessMenu().
 * `module` = nama prop Inertia yang menandakan modulnya aktif; menu modul yang
 * mati disembunyikan dari daftar izin (izin yang tersimpan tetap dipertahankan).
 *
 * Menu khusus admin (Pengaturan, Tim & Akses, outlet, KPI, log aktivitas, login
 * kasir) sengaja tidak ada di sini: rutenya hanya untuk admin/pemilik toko.
 */
final class MenuCatalog
{
    /** @var array<int, array{key: string, label: string, group: string, module?: string}> */
    public const ITEMS = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'group' => 'Umum'],

        ['key' => 'pos', 'label' => 'Kasir (POS)', 'group' => 'Kasir'],
        ['key' => 'pos_transactions', 'label' => 'Riwayat Transaksi', 'group' => 'Kasir'],
        ['key' => 'cash_shifts', 'label' => 'Shift Kasir', 'group' => 'Kasir'],
        ['key' => 'cash_transactions', 'label' => 'Kas Masuk/Keluar', 'group' => 'Kasir'],
        ['key' => 'laundry_orders', 'label' => 'Order Laundry', 'group' => 'Kasir', 'module' => 'laundry_enabled'],
        ['key' => 'table_orders', 'label' => 'Pesanan Masuk (Scan Meja)', 'group' => 'Kasir', 'module' => 'scan_meja_enabled'],
        ['key' => 'tables', 'label' => 'Manajemen Meja', 'group' => 'Kasir', 'module' => 'scan_meja_enabled'],
        ['key' => 'kitchen_queue', 'label' => 'Antrian Dapur', 'group' => 'Kasir', 'module' => 'kitchen_queue_enabled'],
        ['key' => 'queues', 'label' => 'Antrian', 'group' => 'Kasir', 'module' => 'queue_module_enabled'],

        ['key' => 'stock_overview', 'label' => 'Lihat Stok', 'group' => 'Produk & Stok'],
        ['key' => 'products', 'label' => 'Produk', 'group' => 'Produk & Stok'],
        ['key' => 'product_categories', 'label' => 'Kategori Produk', 'group' => 'Produk & Stok'],
        ['key' => 'stock_movements', 'label' => 'Riwayat Stok', 'group' => 'Produk & Stok'],
        ['key' => 'stock_adjustment', 'label' => 'Penyesuaian Stok', 'group' => 'Produk & Stok'],
        ['key' => 'stock_opnames', 'label' => 'Stok Opname', 'group' => 'Produk & Stok'],
        ['key' => 'recipes', 'label' => 'Resep', 'group' => 'Produk & Stok', 'module' => 'recipes_enabled'],
        ['key' => 'productions', 'label' => 'Produksi', 'group' => 'Produk & Stok', 'module' => 'productions_enabled'],

        ['key' => 'shopping_list', 'label' => 'Daftar Belanja', 'group' => 'Pembelian & Distribusi'],
        ['key' => 'purchases', 'label' => 'Pembelian', 'group' => 'Pembelian & Distribusi'],
        ['key' => 'purchase_returns', 'label' => 'Retur Pembelian', 'group' => 'Pembelian & Distribusi'],
        ['key' => 'sales_returns', 'label' => 'Retur Penjualan', 'group' => 'Pembelian & Distribusi'],
        ['key' => 'pre_orders', 'label' => 'Pre-order', 'group' => 'Pembelian & Distribusi'],
        ['key' => 'stock_transfers', 'label' => 'Transfer Stok', 'group' => 'Pembelian & Distribusi'],
        ['key' => 'stock_requests', 'label' => 'Permintaan Stok', 'group' => 'Pembelian & Distribusi', 'module' => 'central_kitchen_enabled'],

        ['key' => 'customers', 'label' => 'Pelanggan', 'group' => 'Pelanggan & Promo'],
        ['key' => 'receivables', 'label' => 'Piutang', 'group' => 'Pelanggan & Promo', 'module' => 'receivables_enabled'],
        ['key' => 'loyalty', 'label' => 'Loyalitas', 'group' => 'Pelanggan & Promo'],
        ['key' => 'discounts', 'label' => 'Diskon', 'group' => 'Pelanggan & Promo'],

        ['key' => 'reports', 'label' => 'Laporan', 'group' => 'Laporan & Keuangan'],
        ['key' => 'expenses', 'label' => 'Biaya Usaha', 'group' => 'Laporan & Keuangan'],

        ['key' => 'attendance', 'label' => 'Absensi', 'group' => 'SDM', 'module' => 'attendance_enabled'],
        ['key' => 'payroll', 'label' => 'Penggajian', 'group' => 'SDM', 'module' => 'payroll_enabled'],
    ];

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_column(self::ITEMS, 'key');
    }
}
