<?php

namespace App\Support;

use App\Models\ProductBatch;
use App\Models\Store;

final class ExpiringProductBatches
{
    /**
     * Batch dengan ED dalam jendela peringatan toko (atau sudah lewat), sisa stok > 0.
     *
     * @return list<array{id: int, product_id: int, product_name: string, batch_code: string|null, quantity_remaining: float|string, expiry_date: string, expiry_status: 'expired'|'warning'}>
     */
    public static function alertPayload(Store $store, ?int $limit = null): array
    {
        if (! BatchTracking::enabled($store)) {
            return [];
        }

        $warnDays = BatchTracking::expiryWarnDays($store);
        $q = ProductBatch::query()
            ->where('store_id', $store->id)
            ->where('quantity_remaining', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($warnDays)->toDateString())
            ->with('product:id,name')
            ->orderByRaw('expiry_date < ? DESC', [now()->toDateString()])
            ->orderBy('expiry_date')
            ->orderBy('id');

        if ($limit !== null) {
            $q->limit($limit);
        }

        return $q->get()
            ->map(function (ProductBatch $b) {
                $today = now()->startOfDay();
                $ed = $b->expiry_date?->copy()->startOfDay();

                return [
                    'id' => $b->id,
                    'product_id' => $b->product_id,
                    'product_name' => $b->product?->name ?? 'Produk',
                    'batch_code' => $b->batch_code,
                    'quantity_remaining' => $b->quantity_remaining,
                    'expiry_date' => $b->expiry_date?->format('Y-m-d') ?? '',
                    'expiry_status' => ($ed && $ed->lt($today)) ? 'expired' : 'warning',
                ];
            })
            ->values()
            ->all();
    }
}
