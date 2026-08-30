<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use App\Support\StorePlanLimits;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /** Fitur paket GrowUp (slug tetap `bisnis` / `bisnis-tahunan` di database). */
    private static function growUpFeatures(): array
    {
        return [
            'Kelola Produk (SKU) Tanpa Batas',
            'Kelola Stok Persediaan Tanpa Batas',
            'Transaksi Tanpa Batas per Hari',
            'Struk Print',
            'Akses Laporan (Rentang Tanggal Hingga 1 Tahun)',
            'Promo Tanpa Batas',
            'Hingga 3 Outlet',
            'Opsi Produk',
            'Varian Produk',
            'Jumlah Desimal (kuantitas / harga)',
            'Split Nota',
            'Shift Kasir',
            'Inventori (produk, riwayat stok, stok opname)',
            'Laporan Transaksi',
            'Laporan Pendapatan',
            'Laporan Penjualan',
            'Laporan Shift Kasir',
            'Laporan Laba Rugi',
            'Export Laporan Excel',
            'Barcode Scanner',
            'Free Support',
            'Transfer stok antar outlet (dalam grup usaha)',
            'Pembelian & retur pembelian',
            'Pre-order pelanggan',
            'Produk paket (bundle) & komponen',
            'Multi-peran: kasir, gudang, manajer, admin toko',
            'Antrian dapur / kitchen (mode F&B)',
        ];
    }

    public function run(): void
    {
        SubscriptionPlan::query()
            ->whereIn('slug', ['basic', 'premium', 'enterprise'])
            ->update(['is_active' => false]);

        // Fix data lama: "Hingga 5 Outlet" → "Hingga 3 Outlet"
        SubscriptionPlan::query()
            ->whereJsonContains('features', 'Hingga 5 Outlet')
            ->each(function (SubscriptionPlan $plan) {
                $features = $plan->features;
                $features = array_map(fn (string $f) => str_replace('Hingga 5 Outlet', 'Hingga 3 Outlet', $f), $features);
                $plan->update(['features' => $features]);
            });

        $growupFeatures = self::growUpFeatures();

        $plans = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'Untuk coba dan toko kecil — gratis, tanpa batas masa aktif',
                'price' => 0,
                'duration_days' => 0,
                'max_products' => StorePlanLimits::STARTER_MAX_PRODUCTS,
                'max_customers' => 50,
                'max_transactions' => 50,
                'max_stores' => 1,
                'features' => [
                    'Hingga '.StorePlanLimits::STARTER_MAX_PRODUCTS.' produk',
                    'POS dasar & cetak struk',
                    'Laporan singkat',
                ],
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 1,
                'billing_cycle' => 'monthly',
            ],
            [
                'name' => 'GrowUp',
                'slug' => 'bisnis',
                'description' => 'Pertumbuhan outlet: operasional penuh, laporan mendalam, multi-outlet',
                'price' => 99000,
                'duration_days' => 30,
                'max_products' => null,
                'max_customers' => null,
                'max_stores' => 3,
                'features' => $growupFeatures,
                'is_active' => true,
                'is_popular' => true,
                'sort_order' => 2,
                'billing_cycle' => 'monthly',
            ],
            [
                'name' => 'Promo onboarding mitra baru',
                'slug' => 'promo-onboarding',
                'description' => 'Diskon setup konsultan & migrasi data awal (syarat & ketentuan berlaku).',
                'price' => 0,
                'duration_days' => 30,
                'max_products' => null,
                'max_customers' => null,
                'max_stores' => null,
                'features' => [],
                'is_active' => false,
                'is_popular' => false,
                'sort_order' => 3,
                'billing_cycle' => 'monthly',
            ],
            [
                'name' => 'Starter (tahunan)',
                'slug' => 'starter-tahunan',
                'description' => 'Untuk coba dan toko kecil — gratis, tanpa batas masa aktif',
                'price' => 0,
                'duration_days' => 0,
                'max_products' => StorePlanLimits::STARTER_MAX_PRODUCTS,
                'max_customers' => 50,
                'max_transactions' => 50,
                'max_stores' => 1,
                'features' => [
                    'Hingga '.StorePlanLimits::STARTER_MAX_PRODUCTS.' produk',
                    'POS dasar & cetak struk',
                    'Laporan singkat',
                ],
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 4,
                'billing_cycle' => 'yearly',
            ],
            [
                'name' => 'GrowUp (tahunan)',
                'slug' => 'bisnis-tahunan',
                'description' => 'GrowUp tahunan — lebih hemat vs bayar bulanan 12×',
                'price' => 990000,
                'duration_days' => 365,
                'max_products' => null,
                'max_customers' => null,
                'max_stores' => 3,
                'features' => $growupFeatures,
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 5,
                'billing_cycle' => 'yearly',
            ],
            [
                'name' => 'Promo tahunan (12 bulan bayar 8)',
                'slug' => 'promo-onboarding-tahunan',
                'description' => 'Harga PROMO: langganan 12 bulan bayar 8 bulan. Nikmati semua fitur tanpa batas.',
                'price' => 792000,
                'duration_days' => 365,
                'max_products' => null,
                'max_customers' => null,
                'max_stores' => 3,
                'features' => $growupFeatures,
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 6,
                'billing_cycle' => 'yearly',
            ],
        ];

        foreach ($plans as $plan) {
            // Satu baris per slug: perbarui jika ada, buat jika belum (DB baru tidak tetap kosong).
            SubscriptionPlan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan
            );
        }
    }
}
