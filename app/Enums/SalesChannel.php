<?php

namespace App\Enums;

/**
 * Channel penjualan untuk pencatatan asal transaksi.
 *
 * Dimensi ini terpisah dari:
 * - order_type (dine_in/takeaway/delivery) → bagaimana disajikan
 * - payment_method (cash/ewallet/...) → cara bayar
 *
 * Contoh: transaksi GoFood punya:
 * - sales_channel = gofood
 * - order_type = delivery
 * - payment_method = ewallet (GoPay)
 */
enum SalesChannel: string
{
    // --- OFFLINE ---
    case WalkIn = 'walk_in';
    case DineIn = 'dine_in';
    case Takeaway = 'takeaway';
    case Delivery = 'delivery';

    // --- ONLINE (Aggregator F&B) ---
    case GoFood = 'gofood';
    case GrabFood = 'grabfood';
    case ShopeeFood = 'shopeefood';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn => 'Beli Langsung',
            self::DineIn => 'Makan di Tempat',
            self::Takeaway => 'Bawa Pulang',
            self::Delivery => 'Antar Sendiri',
            self::GoFood => 'GoFood',
            self::GrabFood => 'GrabFood',
            self::ShopeeFood => 'ShopeeFood',
        };
    }

    /**
     * Klasifikasi channel: offline (pelanggan di toko) atau online (aggregator/online).
     */
    public function group(): string
    {
        return $this->isOnline() ? 'online' : 'offline';
    }

    public function isOffline(): bool
    {
        return in_array($this, [
            self::WalkIn,
            self::DineIn,
            self::Takeaway,
            self::Delivery,
        ], true);
    }

    public function isOnline(): bool
    {
        return ! $this->isOffline();
    }

    /**
     * Aggregator pihak ketiga yang memotong komisi (GoFood/GrabFood/ShopeeFood).
     */
    public function isAggregator(): bool
    {
        return in_array($this, [
            self::GoFood,
            self::GrabFood,
            self::ShopeeFood,
        ], true);
    }

    /**
     * Persentase komisi default per aggregator (bisa di-override per transaksi).
     * Non-aggregator mengembalikan 0.0.
     *
     * @return float<int, float>
     */
    public function defaultCommissionPercent(): float
    {
        return match ($this) {
            self::GoFood => 20.0,
            self::GrabFood => 20.0,
            self::ShopeeFood => 15.0,
            default => 0.0,
        };
    }

    /**
     * Daftar channel yang relevan untuk toko F&B.
     *
     * @return array<int, self>
     */
    public static function forFnb(): array
    {
        return [
            self::WalkIn,
            self::DineIn,
            self::Takeaway,
            self::Delivery,
            self::GoFood,
            self::GrabFood,
            self::ShopeeFood,
        ];
    }

    /**
     * Daftar channel aggregator F&B saja.
     *
     * @return array<int, self>
     */
    public static function aggregators(): array
    {
        return [
            self::GoFood,
            self::GrabFood,
            self::ShopeeFood,
        ];
    }
}
