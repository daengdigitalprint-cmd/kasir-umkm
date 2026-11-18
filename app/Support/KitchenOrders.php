<?php

namespace App\Support;

use App\Events\KitchenQueueUpdated;
use App\Models\Queue;
use App\Models\QueueEntry;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pesanan ke Papan Dapur (F&B).
 *
 * Hanya berlaku untuk toko yang menyalakan "Antrian Dapur" (kitchen_queue_enabled).
 * Toko yang memakai "Antrian Pelanggan" (queue_module_enabled, mis. barbershop) tetap
 * berjalan seperti sebelumnya: entri dibuat di antrean aktif pertama dengan nomor berlanjut.
 */
final class KitchenOrders
{
    public const KITCHEN_QUEUE_CODE = 'DAPUR';

    /** Papan dapur aktif untuk toko ini. */
    public static function enabled(?Store $store): bool
    {
        return $store !== null && PlanModules::settingOn($store, 'kitchen_queue_enabled');
    }

    /** Pesanan perlu dibuatkan entri antrean (dapur, atau antrean pelanggan lama). */
    public static function shouldEnqueue(Store $store): bool
    {
        return self::enabled($store) || (bool) ($store->settings['queue_module_enabled'] ?? false);
    }

    /**
     * Buat entri antrean untuk transaksi; aman dipanggil berulang (satu entri per transaksi).
     * Tidak pernah melempar error ke kasir: kegagalan hanya dicatat di log.
     *
     * @param  array<string, mixed>  $attrs  order_type, table_number, customer_name, customer_phone, barber_user_id, notes
     */
    public static function enqueue(Store $store, Transaction $transaction, array $attrs = []): ?QueueEntry
    {
        if (! self::shouldEnqueue($store)) {
            return null;
        }

        try {
            $existing = QueueEntry::query()->where('transaction_id', $transaction->id)->first();
            if ($existing) {
                return $existing;
            }

            $entry = DB::transaction(function () use ($store, $transaction, $attrs) {
                $kitchenOnly = ! (bool) ($store->settings['queue_module_enabled'] ?? false);
                $queue = $kitchenOnly ? self::kitchenQueue($store) : self::legacyQueue($store);

                // Kunci baris antrean agar dua kasir tidak mendapat nomor yang sama.
                Queue::query()->whereKey($queue->id)->lockForUpdate()->first();

                $numbers = $queue->entries();
                if ($kitchenOnly) {
                    // Nomor dapur mulai dari 1 setiap hari.
                    $numbers->whereDate('created_at', today());
                }
                $next = ((int) $numbers->max('number')) + 1;

                return $queue->entries()->create(array_merge([
                    'store_id' => $store->id,
                    'number' => $next,
                    'status' => 'waiting',
                    'transaction_id' => $transaction->id,
                    'items' => self::itemsFor($transaction),
                ], array_filter($attrs, fn ($v) => $v !== null && $v !== '')));
            });

            self::broadcast(KitchenQueueUpdated::fromEntry($entry->load('transaction:id,transaction_number,order_type,table_reference')));

            return $entry;
        } catch (\Throwable $e) {
            Log::warning('[dapur] gagal membuat antrean dapur', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Item untuk dapur: nama, jumlah, varian, dan pilihan/catatan (mis. "less sugar").
     *
     * @return list<array{product_name: string, quantity: int|float, variant: string|null, modifiers: list<array{label: string}>}>
     */
    public static function itemsFor(Transaction $transaction): array
    {
        $transaction->loadMissing('items.product.productCategory:id,name');

        return $transaction->items->map(function ($item) {
            $qty = (float) $item->quantity;
            $category = $item->product?->productCategory;

            return [
                'product_name' => (string) $item->product_name,
                'quantity' => floor($qty) == $qty ? (int) $qty : $qty,
                'variant' => $item->variant_label ?: null,
                // Untuk filter stasiun (mis. bar = minuman, dapur = makanan).
                'category_id' => $category?->id,
                'category' => $category?->name,
                'done' => false,
                'modifiers' => collect($item->modifiers ?? [])
                    ->map(fn ($m) => ['label' => (string) ($m['label'] ?? '')])
                    ->filter(fn ($m) => $m['label'] !== '')
                    ->values()
                    ->all(),
            ];
        })->values()->all();
    }

    /** Pesanan dibatalkan/di-void di kasir → hilang dari papan dapur. Hanya untuk toko dengan papan dapur. */
    public static function cancelForTransaction(Store $store, Transaction $transaction): void
    {
        if (! self::enabled($store)) {
            return;
        }

        try {
            $entries = QueueEntry::query()
                ->where('transaction_id', $transaction->id)
                ->whereIn('status', ['waiting', 'serving', 'ready'])
                ->get();

            foreach ($entries as $entry) {
                $entry->update(['status' => 'cancelled']);
                self::broadcast(KitchenQueueUpdated::deleted((int) $store->id, (int) $entry->id));
            }
        } catch (\Throwable $e) {
            Log::warning('[dapur] gagal membatalkan antrean dapur', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Kirim pembaruan ke layar dapur tanpa menggagalkan proses bila server WebSocket mati;
     * layar dapur tetap menyegarkan diri secara berkala.
     */
    public static function broadcast(KitchenQueueUpdated $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            Log::notice('[dapur] siaran layar dapur gagal; layar memakai cek berkala', ['error' => $e->getMessage()]);
        }
    }

    private static function kitchenQueue(Store $store): Queue
    {
        return $store->queues()->firstOrCreate(
            ['code' => self::KITCHEN_QUEUE_CODE],
            ['name' => 'Dapur', 'is_active' => true],
        );
    }

    /** Perilaku lama "Antrian Pelanggan": antrean aktif pertama, atau buat "Kasir". */
    private static function legacyQueue(Store $store): Queue
    {
        return $store->queues()->where('is_active', true)->first()
            ?? $store->queues()->create(['name' => 'Kasir', 'code' => 'Q001', 'is_active' => true]);
    }
}
