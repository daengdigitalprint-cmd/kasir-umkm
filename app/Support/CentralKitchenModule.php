<?php

namespace App\Support;

use App\Models\Store;

/**
 * R2 — Modul Central Kitchen (add-on per grup usaha).
 *
 * Aktif bila grup usaha toko punya `central_kitchen_until` di masa depan.
 * Membuka Permintaan Stok (F6) dan Pantau Stok Semua Lokasi (F7).
 * Toko tanpa grup usaha tidak bisa memakainya (tidak ada lokasi lain).
 */
final class CentralKitchenModule
{
    public static function enabled(?Store $store): bool
    {
        $until = $store?->organization?->central_kitchen_until;

        return $store !== null
            && $store->organization_id !== null
            && $until !== null
            && $until->isFuture();
    }

    /** Lokasi aktif lain dalam grup yang sama (calon pemasok/peminta). */
    public static function siblingStores(Store $store): \Illuminate\Support\Collection
    {
        if ($store->organization_id === null) {
            return collect();
        }

        return Store::query()
            ->where('organization_id', $store->organization_id)
            ->whereKeyNot($store->id)
            ->where('is_active', true)
            ->orderByRaw('CASE WHEN location_type = ? THEN 0 ELSE 1 END', [StoreLocationType::CENTRAL_WAREHOUSE])
            ->orderBy('name')
            ->get(['id', 'name', 'location_type', 'organization_id']);
    }
}
