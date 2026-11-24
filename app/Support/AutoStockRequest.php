<?php

namespace App\Support;

use App\Actions\StockRequestWorkflow;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Store;
use Illuminate\Support\Carbon;

/**
 * Order otomatis ke Gudang Pusat (Gudang Pusat Pro).
 *
 * Setiap hari pada jam yang diatur outlet, Daftar Belanja outlet (barang di bawah
 * Stok Minimum) dikirim sebagai satu Permintaan Stok ke lokasi pemasok (bawaan:
 * Gudang Pusat). CK tetap merevisi & menyetujui seperti permintaan biasa.
 */
final class AutoStockRequest
{
    public const SETTINGS_KEY = 'auto_stock_request';

    public const DEFAULT_TIME = '21:00';

    /** @return array{enabled: bool, time: string, supplier_store_id: int|null, last_run_date: string|null} */
    public static function settings(Store $store): array
    {
        $saved = (array) ($store->settings[self::SETTINGS_KEY] ?? []);
        $time = (string) ($saved['time'] ?? self::DEFAULT_TIME);

        return [
            'enabled' => (bool) ($saved['enabled'] ?? false),
            'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : self::DEFAULT_TIME,
            'supplier_store_id' => isset($saved['supplier_store_id']) ? (int) $saved['supplier_store_id'] : null,
            'last_run_date' => $saved['last_run_date'] ?? null,
        ];
    }

    public static function saveSettings(Store $store, array $values): void
    {
        $all = $store->settings ?? [];
        $all[self::SETTINGS_KEY] = array_merge(self::settings($store), $values);
        $store->update(['settings' => $all]);
    }

    /** Lokasi pemasok yang dipakai: pilihan outlet bila masih valid, selain itu Gudang Pusat grup. */
    public static function supplierFor(Store $store): ?Store
    {
        $siblings = CentralKitchenModule::siblingStores($store);
        $chosen = self::settings($store)['supplier_store_id'];

        $picked = ($chosen ? $siblings->firstWhere('id', $chosen) : null)
            ?? $siblings->first(fn (Store $s) => StoreLocationType::isCentralWarehouse($s));

        // siblingStores() hanya memuat sebagian kolom; alur permintaan butuh model lengkap.
        return $picked ? Store::query()->find($picked->id) : null;
    }

    /** Waktunya membuat permintaan hari ini (menurut zona waktu toko)? */
    public static function isDue(Store $store, ?Carbon $now = null): bool
    {
        $settings = self::settings($store);
        if (! $settings['enabled'] || ! CentralKitchenModule::enabled($store) || StoreLocationType::isCentralWarehouse($store)) {
            return false;
        }

        $local = ($now ?? now())->copy()->setTimezone($store->timezone ?: config('app.timezone'));

        return $local->format('H:i') >= $settings['time']
            && $settings['last_run_date'] !== $local->toDateString();
    }

    /**
     * Barang yang akan diminta: Daftar Belanja tanpa varian, yang dipunyai pemasok,
     * dan belum ada di permintaan yang masih terbuka (supaya tidak dobel).
     *
     * @return list<array{product_id: int, quantity: float}>
     */
    public static function items(Store $store, Store $supplier): array
    {
        $openProductIds = StockRequestLine::query()
            ->whereHas('request', fn ($q) => $q->where('from_store_id', $store->id)
                ->whereIn('status', [StockRequest::STATUS_REQUESTED, StockRequest::STATUS_APPROVED]))
            ->pluck('requester_product_id')
            ->flip();

        $workflow = app(StockRequestWorkflow::class);
        $items = [];
        foreach (ShoppingList::items($store) as $row) {
            if ($row['product_variant_id'] !== null || $openProductIds->has($row['product_id'])) {
                continue;
            }
            $product = \App\Models\Product::query()->find($row['product_id']);
            if (! $product || ! StockRequestWorkflow::isTransferable($product) || ! $workflow->matchingProduct($supplier, $product)) {
                continue;
            }
            $items[] = ['product_id' => $product->id, 'quantity' => (float) $row['suggested_quantity']];
        }

        return $items;
    }

    /**
     * Buat permintaan otomatis sekarang. Null bila tidak ada barang yang perlu diminta
     * atau tidak ada pemasok. Tanggal jalan terakhir selalu dicatat agar jadwal tidak berulang.
     */
    public static function run(Store $store): ?StockRequest
    {
        $today = now()->setTimezone($store->timezone ?: config('app.timezone'))->toDateString();
        $supplier = self::supplierFor($store);
        $items = $supplier ? self::items($store, $supplier) : [];

        self::saveSettings($store, ['last_run_date' => $today]);

        if ($supplier === null || $items === []) {
            return null;
        }

        return app(StockRequestWorkflow::class)->create(
            null,
            $store,
            $supplier,
            $items,
            'Dibuat otomatis dari Daftar Belanja ('.count($items).' barang di bawah Stok Minimum).',
            automatic: true,
            // Outlet yang biasa membeli ke gudang tetap membeli; selain itu transfer biasa.
            mode: InternalPurchase::defaultMode($store),
        );
    }
}
