<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Support\Collection;

/**
 * F7 — Pantau Stok Semua Lokasi.
 *
 * Menyusun tabel produk × lokasi untuk owner. Produk dari lokasi berbeda dianggap
 * barang yang sama bila SKU-nya sama (tanpa beda huruf/spasi); produk tanpa SKU
 * dicocokkan lewat nama. Hanya barang yang stoknya dilacak (tanpa varian/paket).
 */
final class MultiLocationStockService
{
    public const STATUS_OUT = 'out';

    public const STATUS_LOW = 'low';

    public const STATUS_OK = 'ok';

    /**
     * Lokasi yang boleh dipantau: toko aktif tempat user menjadi owner,
     * dalam grup usaha yang sama dengan lokasi aktif.
     *
     * @return Collection<int, Store>
     */
    public function storesFor(User $user): Collection
    {
        $active = $user->store;
        if (! $active || $active->organization_id === null) {
            return collect();
        }

        $query = Store::query()
            ->where('organization_id', $active->organization_id)
            ->where('is_active', true);

        if (! $user->isSuperAdmin()) {
            $ownedIds = $user->stores()->wherePivot('role', 'owner')->pluck('stores.id');
            if ($user->getRoleForActiveStore() === 'owner') {
                $ownedIds->push($active->id);
            }
            $query->whereIn('id', $ownedIds->unique()->values());
        }

        // Gudang Pusat di kolom pertama, lalu outlet urut nama.
        return $query
            ->orderByRaw('CASE WHEN location_type = ? THEN 0 ELSE 1 END', [StoreLocationType::CENTRAL_WAREHOUSE])
            ->orderBy('name')
            ->get(['id', 'name', 'location_type', 'organization_id']);
    }

    /**
     * @param  Collection<int, Store>  $stores
     * @return list<array{key: string, name: string, sku: string|null, unit: string, total: float, problem: bool, locations: array<int, array{stock: float, min: float, status: string}|null>}>
     */
    public function rows(Collection $stores, ?string $search = null, bool $problemsOnly = false): array
    {
        if ($stores->isEmpty()) {
            return [];
        }

        $products = Product::query()
            ->whereIn('store_id', $stores->pluck('id'))
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where('unlimited_stock', false)
            ->where('has_variants', false)
            ->where('is_bundle', false)
            ->when($search !== null && trim($search) !== '', function ($q) use ($search) {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(sku) LIKE ?', [$term]));
            })
            ->orderBy('name')
            ->get(['id', 'store_id', 'name', 'sku', 'base_unit', 'stock_quantity', 'min_stock_level']);

        $rows = [];
        foreach ($products as $product) {
            $key = self::matchKey($product);
            $rows[$key] ??= [
                'key' => $key,
                'name' => $product->name,
                'sku' => $product->sku ?: null,
                'unit' => $product->baseUnit(),
                'total' => 0.0,
                'problem' => false,
                'locations' => array_fill_keys($stores->pluck('id')->all(), null),
            ];

            $stock = (float) $product->stock_quantity;
            $min = (float) $product->min_stock_level;
            $status = self::status($stock, $min);

            // Dua produk dengan kunci sama di satu lokasi: jumlahkan stoknya.
            $existing = $rows[$key]['locations'][$product->store_id];
            if ($existing !== null) {
                $stock += $existing['stock'];
                $min = max($min, $existing['min']);
                $status = self::status($stock, $min);
            }

            $rows[$key]['locations'][$product->store_id] = ['stock' => $stock, 'min' => $min, 'status' => $status];
        }

        foreach ($rows as &$row) {
            $row['total'] = array_sum(array_map(fn ($cell) => $cell['stock'] ?? 0, $row['locations']));
            $row['problem'] = collect($row['locations'])->contains(
                fn ($cell) => $cell !== null && $cell['status'] !== self::STATUS_OK
            );
        }
        unset($row);

        $rows = array_values($rows);
        if ($problemsOnly) {
            $rows = array_values(array_filter($rows, fn ($row) => $row['problem']));
        }

        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /** SKU (huruf kecil, tanpa spasi tepi) bila ada; selain itu nama. */
    public static function matchKey(Product $product): string
    {
        $sku = mb_strtolower(trim((string) $product->sku));
        if ($sku !== '') {
            return 'sku:'.$sku;
        }

        return 'name:'.mb_strtolower(preg_replace('/\s+/', ' ', trim($product->name)));
    }

    /** Habis (≤ 0), menipis (≤ Stok Minimum yang diatur), atau aman. */
    public static function status(float $stock, float $min): string
    {
        if ($stock <= 0) {
            return self::STATUS_OUT;
        }

        return $min > 0 && $stock <= $min ? self::STATUS_LOW : self::STATUS_OK;
    }
}
