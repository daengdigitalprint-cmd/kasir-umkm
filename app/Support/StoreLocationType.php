<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Validation\ValidationException;

/**
 * Klasifikasi lokasi stok (Tahap 1 modul gudang/produksi).
 *
 * Gudang Pusat memakai entitas Store agar produk, pembelian, stok, mutasi, dan
 * transfer tetap memakai relasi `store_id` yang sudah ada. Kebijakan awal:
 * satu Gudang Pusat per grup usaha (organization).
 */
final class StoreLocationType
{
    public const CENTRAL_WAREHOUSE = 'central_warehouse';

    public const OUTLET = 'outlet';

    /** @var array<string, string> */
    public const LABELS = [
        self::OUTLET => 'Outlet / Cabang penjualan',
        self::CENTRAL_WAREHOUSE => 'Gudang Pusat',
    ];

    public static function isValid(string $type): bool
    {
        return array_key_exists($type, self::LABELS);
    }

    public static function normalize(mixed $raw): string
    {
        $value = is_string($raw) ? trim($raw) : '';

        return self::isValid($value) ? $value : self::OUTLET;
    }

    public static function of(Store $store): string
    {
        return self::normalize($store->location_type);
    }

    public static function isCentralWarehouse(Store $store): bool
    {
        return self::of($store) === self::CENTRAL_WAREHOUSE;
    }

    public static function isOutlet(Store $store): bool
    {
        return self::of($store) === self::OUTLET;
    }

    /**
     * Toko memakai sistem gudang: merupakan Gudang Pusat itu sendiri, atau
     * pemiliknya menyalakan toggle "sistem gudang" di Pengaturan dan grup
     * usahanya (organization) memiliki Gudang Pusat.
     *
     * Toko tanpa sistem gudang — toggle mati, toko mandiri/standalone, atau grup
     * usaha tanpa Gudang Pusat — dapat menjalankan fungsi pembelian secara mandiri
     * tanpa ketergantungan pada gudang pusat.
     */
    public static function usesWarehouseSystem(Store $store): bool
    {
        if (self::isCentralWarehouse($store)) {
            return true;
        }

        if ($store->organization_id === null) {
            return false;
        }

        $enabled = (bool) ($store->settings['warehouse_enabled'] ?? false);

        return $enabled && self::centralWarehouseFor((int) $store->organization_id) !== null;
    }

    /**
     * Pembelian supplier wajib dicatat di Gudang Pusat, tetapi hanya untuk
     * toko yang memakai sistem gudang dan bukan Gudang Pusat itu sendiri
     * (outlet dalam grup usaha yang memiliki Gudang Pusat).
     *
     * Keluar dari aturan ini, toko tanpa sistem gudang bebas melakukan
     * pembelian mandiri.
     */
    public static function mustRoutePurchaseThroughCentralWarehouse(Store $store): bool
    {
        return ! self::isCentralWarehouse($store) && self::usesWarehouseSystem($store);
    }

    public static function label(Store $store): string
    {
        return self::LABELS[self::of($store)];
    }

    /**
     * Gudang Pusat aktif milik satu grup usaha.
     */
    public static function centralWarehouseFor(?int $organizationId): ?Store
    {
        if ($organizationId === null) {
            return null;
        }

        return Store::query()
            ->where('organization_id', $organizationId)
            ->where('location_type', self::CENTRAL_WAREHOUSE)
            ->orderBy('id')
            ->first();
    }

    /**
     * Kebijakan awal: satu Gudang Pusat per grup usaha.
     *
     * @throws ValidationException
     */
    public static function assertOrganizationHasNoCentralWarehouse(
        ?int $organizationId,
        ?int $ignoreStoreId = null,
        string $errorKey = 'location_type',
    ): void {
        if ($organizationId === null) {
            throw ValidationException::withMessages([
                $errorKey => 'Gudang Pusat harus berada dalam grup usaha. Pilih atau buat grup usaha terlebih dahulu.',
            ]);
        }

        $existing = Store::query()
            ->where('organization_id', $organizationId)
            ->where('location_type', self::CENTRAL_WAREHOUSE)
            ->when($ignoreStoreId !== null, fn ($q) => $q->whereKeyNot($ignoreStoreId))
            ->first(['id', 'name']);

        if ($existing !== null) {
            throw ValidationException::withMessages([
                $errorKey => "Grup usaha ini sudah memiliki Gudang Pusat «{$existing->name}». Satu grup usaha hanya boleh punya satu Gudang Pusat.",
            ]);
        }
    }

    /**
     * Stok hanya boleh mengalir antar-lokasi aktif dalam satu grup usaha.
     */
    public static function sharesOrganization(Store $a, Store $b): bool
    {
        if ($a->organization_id === null || $b->organization_id === null) {
            return false;
        }

        return (int) $a->organization_id === (int) $b->organization_id;
    }
}
