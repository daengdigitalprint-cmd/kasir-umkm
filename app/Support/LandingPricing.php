<?php

namespace App\Support;

use App\Models\SubscriptionPlan;

/**
 * Data paket harga untuk section "Harga" di landing page.
 *
 * Seluruh angka diambil dari tabel subscription_plans (yang diatur Super
 * Admin), bukan ditulis di komponen. Format tampilannya juga disusun di
 * sini supaya komponen Vue dan HTML prerender memakai teks yang sama persis.
 */
final class LandingPricing
{
    /** Slug paket yang ditampilkan di landing. */
    private const SLUG_FREE = 'starter';

    private const SLUG_MONTHLY = 'bisnis';

    private const SLUG_YEARLY = 'bisnis-tahunan';

    /**
     * Payload untuk komponen LandingPricing.vue.
     *
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        // Satu query untuk ketiga paket. Sengaja tanpa cache: harga harus
        // langsung ikut berubah begitu Super Admin menyimpannya.
        //
        // Halaman depan tidak boleh tumbang gara-gara section harga, jadi
        // kegagalan database (migrasi berjalan, instalasi baru) ditelan dan
        // kartu harga tampil tanpa angka — bukan 500.
        try {
            $plans = SubscriptionPlan::query()
                ->whereIn('slug', [self::SLUG_FREE, self::SLUG_MONTHLY, self::SLUG_YEARLY])
                ->get()
                ->keyBy('slug');
        } catch (\Throwable) {
            $plans = collect();
        }

        $free = $plans->get(self::SLUG_FREE);
        $monthly = $plans->get(self::SLUG_MONTHLY);
        $yearly = $plans->get(self::SLUG_YEARLY);

        $monthlyPrice = $monthly ? (int) round((float) $monthly->price) : null;
        $yearlyPrice = $yearly ? (int) round((float) $yearly->price) : null;

        return [
            'free' => [
                'name' => $free?->name ?? 'Starter',
                'description' => $free?->description ?: 'Gratis untuk coba dan toko kecil.',
                // Paket gratis aman diberi label tetap: harganya memang nol.
                'price_label' => self::formatPrice((int) round((float) ($free?->price ?? 0))),
                'max_products' => self::limit($free?->max_products),
                'max_customers' => self::limit($free?->max_customers),
                'max_stores' => self::limit($free?->max_stores) ?? 1,
            ],
            'paid' => [
                'name' => $monthly?->name ?? 'GrowUp',
                'description' => $monthly?->description
                    ?: 'Untuk pertumbuhan outlet: operasional penuh, laporan mendalam, multi-outlet.',
                // Kosong bila paketnya tidak terbaca: lebih baik tidak
                // menampilkan angka daripada menampilkan angka yang salah.
                'monthly_label' => $monthlyPrice !== null ? self::formatPrice($monthlyPrice) : '',
                'yearly_label' => $yearlyPrice !== null ? self::formatPrice($yearlyPrice) : '',
                'max_stores' => self::limit($monthly?->max_stores) ?? 3,
            ],
            // Dipakai untuk coretan "Hemat N Bulan"; null bila tahunan tidak
            // lebih murah, supaya landing tidak menjanjikan diskon yang tidak ada.
            'yearly_saving_months' => ($monthlyPrice !== null && $yearlyPrice !== null)
                ? self::savingMonths($monthlyPrice, $yearlyPrice)
                : null,
        ];
    }

    /**
     * Teks harga yang dipakai di kartu.
     *
     * Dibulatkan hanya bila tidak kehilangan ketelitian: kelipatan satu juta
     * jadi "Rp2 jt", kelipatan seribu di bawah sejuta jadi "Rp99 rb", sisanya
     * ditulis penuh ("Rp1.188.000") agar tidak ada angka yang dibulatkan dan
     * menyesatkan calon pembeli.
     */
    public static function formatPrice(int $amount): string
    {
        if ($amount <= 0) {
            return 'Gratis';
        }

        if ($amount % 1_000_000 === 0) {
            return 'Rp'.number_format($amount / 1_000_000, 0, ',', '.').' jt';
        }

        if ($amount < 1_000_000 && $amount % 1_000 === 0) {
            return 'Rp'.number_format($amount / 1_000, 0, ',', '.').' rb';
        }

        return 'Rp'.number_format($amount, 0, ',', '.');
    }

    /**
     * Berapa bulan yang dihemat bila berlangganan tahunan.
     * null = tidak ada penghematan.
     */
    private static function savingMonths(int $monthlyPrice, int $yearlyPrice): ?int
    {
        if ($monthlyPrice < 1) {
            return null;
        }

        $saving = ($monthlyPrice * 12) - $yearlyPrice;

        if ($saving < $monthlyPrice) {
            return null;
        }

        return (int) floor($saving / $monthlyPrice);
    }

    /** Batas kuota; null berarti tanpa batas. */
    private static function limit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
